<?php

namespace Tests\Feature;

use App\Exceptions\CargoException;
use App\Exceptions\TankCapacityException;
use App\Models\Commodity;
use App\Models\Movement;
use App\Models\Shipment;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\Vessel;
use App\Services\CargoService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CargoTimelineTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{Shipment, Tank, Truck} */
    private function cargo(): array
    {
        $commodity = Commodity::factory()->create(['code' => 'CPO', 'description' => 'Crude Palm Oil']);
        $vessel = Vessel::factory()->create(['name' => 'Vessel']);
        $shipment = Shipment::factory()->create(['reference' => 'S1', 'origin' => 'Origin', 'vessel_id' => $vessel->id, 'commodity_id' => $commodity->id, 'expected_ml' => 100000000]);
        $tank = Tank::factory()->create(['name' => 'Tank', 'capacity_ml' => 100000000]);
        $truck = Truck::factory()->create(['registration' => 'TRUCK']);

        return [$shipment, $tank, $truck];
    }

    /** @return array<string, mixed> */
    private function event(Shipment $shipment, Tank $tank, Truck $truck, string $type, string $reference, string $quantity, string $time): array
    {
        return ['type' => $type, 'event_reference' => $reference, 'shipment_id' => $shipment->id, 'truck_id' => $truck->id, 'tank_id' => $tank->id, 'quantity_litres' => $quantity, 'occurred_at' => $time];
    }

    public function test_shuffled_batch_is_sorted_before_applying_stock(): void
    {
        [$shipment, $tank, $truck] = $this->cargo();
        $shipment->update(['expected_ml' => 200000000]);
        $service = app(CargoService::class);
        $events = [
            $this->event($shipment, $tank, $truck, 'outbound', 'C', '100000', '2026-01-01 12:00:00'),
            $this->event($shipment, $tank, $truck, 'outbound', 'B', '100000', '2026-01-01 10:00:00'),
            $this->event($shipment, $tank, $truck, 'inbound', 'A', '100000', '2026-01-01 10:00:00'),
            $this->event($shipment, $tank, $truck, 'inbound', 'D', '100000', '2026-01-01 11:00:00'),
        ];

        $movements = $service->recordBatch($events);

        $this->assertSame(['A', 'B', 'D', 'C'], array_map(fn (Movement $movement): string => $movement->event_reference, $movements));
        $this->assertSame(0, $tank->fresh()->balance_ml);
        $this->assertNull($tank->fresh()->commodity_id);
        $this->assertDatabaseCount('movements', 4);
    }

    public function test_valid_late_delivery_uses_historical_commodity_when_tank_is_now_empty(): void
    {
        [$shipment, $tank, $truck] = $this->cargo();
        $service = app(CargoService::class);
        $service->record($this->event($shipment, $tank, $truck, 'inbound', 'A', '1000', '2026-01-01 10:00:00'));
        $service->record($this->event($shipment, $tank, $truck, 'outbound', 'D', '1000', '2026-01-01 13:00:00'));
        $history = Movement::all()->toArray();

        $service->recordBatch([
            $this->event($shipment, $tank, $truck, 'outbound', 'C', '1000', '2026-01-01 12:00:00'),
            $this->event($shipment, $tank, $truck, 'inbound', 'B', '1000', '2026-01-01 11:00:00'),
        ]);

        $this->assertSame(0, $tank->fresh()->balance_ml);
        $this->assertSame($history, Movement::whereIn('event_reference', ['A', 'D'])->get()->toArray());
        $this->assertDatabaseHas('movements', ['event_reference' => 'C', 'commodity_id' => $shipment->commodity_id, 'status' => 'applied']);
    }

    public function test_late_receipt_that_overflows_historical_capacity_rolls_back(): void
    {
        [$shipment, $tank, $truck] = $this->cargo();
        $shipment->update(['expected_ml' => 101000000]);
        $service = app(CargoService::class);
        $service->record($this->event($shipment, $tank, $truck, 'inbound', 'A', '100000', '2026-01-01 10:00:00'));
        $service->record($this->event($shipment, $tank, $truck, 'outbound', 'C', '100000', '2026-01-01 12:00:00'));

        try {
            $service->record($this->event($shipment, $tank, $truck, 'inbound', 'B', '1', '2026-01-01 11:00:00'));
            $this->fail('Expected a historical capacity conflict.');
        } catch (TankCapacityException) {
            $this->assertSame(0, $tank->fresh()->balance_ml);
            $this->assertDatabaseCount('movements', 2);
        }
    }

    public function test_late_delivery_cannot_invalidate_an_existing_delivery(): void
    {
        [$shipment, $tank, $truck] = $this->cargo();
        $service = app(CargoService::class);
        $service->record($this->event($shipment, $tank, $truck, 'inbound', 'A', '1000', '2026-01-01 10:00:00'));
        $service->record($this->event($shipment, $tank, $truck, 'outbound', 'C', '900', '2026-01-01 12:00:00'));

        try {
            $service->record($this->event($shipment, $tank, $truck, 'outbound', 'B', '101', '2026-01-01 11:00:00'));
            $this->fail('Expected a historical stock conflict.');
        } catch (CargoException) {
            $this->assertSame(100000, $tank->fresh()->balance_ml);
            $this->assertDatabaseCount('movements', 2);
        }
    }

    public function test_invalid_batch_does_not_partially_apply_valid_events(): void
    {
        [$shipment, $tank, $truck] = $this->cargo();
        try {
            app(CargoService::class)->recordBatch([
                $this->event($shipment, $tank, $truck, 'inbound', 'A', '1000', '2026-01-01 10:00:00'),
                $this->event($shipment, $tank, $truck, 'outbound', 'B', '1001', '2026-01-01 11:00:00'),
            ]);
            $this->fail('Expected batch rollback.');
        } catch (CargoException) {
            $this->assertSame(0, $tank->fresh()->balance_ml);
            $this->assertDatabaseCount('movements', 0);
        }
    }

    public function test_late_receipt_cannot_invalidate_a_later_commodity_change(): void
    {
        [$shipment, $tank, $truck] = $this->cargo();
        $otherCommodity = Commodity::factory()->create(['code' => 'PFAD', 'description' => 'Palm Fatty Acid Distillate']);
        $otherShipment = Shipment::factory()->create(['reference' => 'S2', 'origin' => 'Origin', 'vessel_id' => $shipment->vessel_id, 'commodity_id' => $otherCommodity->id, 'expected_ml' => 1000000]);
        $service = app(CargoService::class);
        $service->recordBatch([
            $this->event($shipment, $tank, $truck, 'inbound', 'A', '1000', '2026-01-01 10:00:00'),
            $this->event($shipment, $tank, $truck, 'outbound', 'C', '1000', '2026-01-01 11:00:00'),
            $this->event($otherShipment, $tank, $truck, 'inbound', 'D', '1000', '2026-01-01 12:00:00'),
        ]);

        try {
            $service->record($this->event($shipment, $tank, $truck, 'inbound', 'B', '1', '2026-01-01 10:30:00'));
            $this->fail('Expected a historical commodity conflict.');
        } catch (CargoException) {
            $this->assertSame(1000000, $tank->fresh()->balance_ml);
            $this->assertSame($otherCommodity->id, $tank->fresh()->commodity_id);
            $this->assertDatabaseCount('movements', 3);
        }
    }

    public function test_invalid_quantities_and_duplicate_batch_references_leave_stock_unchanged(): void
    {
        [$shipment, $tank, $truck] = $this->cargo();
        foreach (['0', '-1', 'NaN', '1.0001'] as $quantity) {
            try {
                app(CargoService::class)->record($this->event($shipment, $tank, $truck, 'inbound', 'A', $quantity, '2026-01-01 10:00:00'));
                $this->fail('Expected invalid quantity rejection.');
            } catch (CargoException) {
                $this->assertSame(0, $tank->fresh()->balance_ml);
            }
        }
        $event = $this->event($shipment, $tank, $truck, 'inbound', 'A', '1000', '2026-01-01 10:00:00');
        try {
            app(CargoService::class)->recordBatch([$event, $event]);
            $this->fail('Expected duplicate rejection.');
        } catch (CargoException) {
            $this->assertDatabaseCount('movements', 0);
        }
    }
}
