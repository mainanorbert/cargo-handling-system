<?php

namespace Tests\Feature;

use App\Exceptions\CargoException;
use App\Exceptions\TankCapacityException;
use App\Filament\Resources\Movements\Pages\ManageMovements;
use App\Filament\Resources\Shipments\Pages\ManageShipments;
use App\Models\Commodity;
use App\Models\Movement;
use App\Models\Shipment;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\User;
use App\Models\Vessel;
use App\Services\CargoService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CargoWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function setupCargo(): array
    {
        $commodity = Commodity::create(['code' => 'CPO', 'description' => 'Crude Palm Oil']);
        $vessel = Vessel::create(['name' => 'Vessel']);
        $shipment = Shipment::create(['reference' => 'S1', 'origin' => 'Origin', 'vessel_id' => $vessel->id, 'commodity_id' => $commodity->id, 'expected_ml' => 100000000]);
        $tank = Tank::create(['name' => 'A', 'capacity_ml' => 99500000]);
        $truck = Truck::create(['registration' => 'TRUCK']);

        return [$shipment, $tank, $truck];
    }

    private function inbound(Shipment $shipment, Tank $tank, string $reference = 'E1', string $quantity = '100000', string $date = '2026-01-01 10:00:00'): Movement
    {
        return app(CargoService::class)->record(['type' => 'inbound', 'event_reference' => $reference, 'shipment_id' => $shipment->id, 'tank_id' => $tank->id, 'quantity_litres' => $quantity, 'occurred_at' => $date]);
    }

    public function test_inbound_loss_and_delivery_update_stock(): void
    {
        [$shipment, $tank, $truck] = $this->setupCargo();
        $movement = $this->inbound($shipment, $tank);
        $this->assertSame(500000, $movement->loss_ml);
        $this->assertSame(99500000, $tank->fresh()->balance_ml);
        app(CargoService::class)->record(['type' => 'outbound', 'event_reference' => 'E2', 'truck_id' => $truck->id, 'tank_id' => $tank->id, 'quantity_litres' => '500', 'occurred_at' => '2026-01-01 11:00:00']);
        $this->assertSame(99000000, $tank->fresh()->balance_ml);
    }

    public function test_overflow_rolls_back(): void
    {
        [$shipment, $tank] = $this->setupCargo();
        try {
            $this->inbound($shipment, $tank, 'E1', '100001');
            $this->fail('Expected overflow');
        } catch (TankCapacityException) {
        }
        $this->assertSame(0, $tank->fresh()->balance_ml);
        $this->assertDatabaseCount('movements', 0);
    }

    public function test_late_event_is_flagged_without_changing_balance(): void
    {
        [$shipment, $tank] = $this->setupCargo();
        $this->inbound($shipment, $tank, 'B', '1000');
        $late = $this->inbound($shipment, $tank, 'A', '1000');
        $this->assertSame('needs_review', $late->status);
        $this->assertSame(995000, $tank->fresh()->balance_ml);
    }

    public function test_invalid_and_duplicate_events_do_not_change_stock(): void
    {
        [$shipment, $tank] = $this->setupCargo();
        $this->inbound($shipment, $tank, 'E1', '1000');
        foreach ([['E1', '1000'], ['E2', '0'], ['E3', '-1']] as [$reference, $quantity]) {
            try {
                $this->inbound($shipment, $tank, $reference, $quantity);
                $this->fail('Expected rejection');
            } catch (CargoException) {
            }
        }
        $this->assertSame(995000, $tank->fresh()->balance_ml);
        $this->assertDatabaseCount('movements', 1);
    }

    public function test_incompatible_receipt_and_excess_delivery_are_rejected(): void
    {
        [$shipment, $tank, $truck] = $this->setupCargo();
        $this->inbound($shipment, $tank, 'E1', '1000');
        $other = Commodity::create(['code' => 'PFAD', 'description' => 'PFAD']);
        $shipment->update(['commodity_id' => $other->id]);
        try {
            $this->inbound($shipment, $tank, 'E2', '1', '2026-01-01 11:00:00');
            $this->fail('Expected incompatible commodity');
        } catch (CargoException) {
        }
        try {
            app(CargoService::class)->record(['type' => 'outbound', 'event_reference' => 'E3', 'truck_id' => $truck->id, 'tank_id' => $tank->id, 'quantity_litres' => '996', 'occurred_at' => '2026-01-01 11:00:00']);
            $this->fail('Expected insufficient stock');
        } catch (CargoException) {
        }
        $this->assertSame(995000, $tank->fresh()->balance_ml);
        $this->assertDatabaseCount('movements', 1);
    }

    public function test_authenticated_operator_can_open_cargo_screens(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['/admin', '/admin/shipments', '/admin/tanks', '/admin/movements'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_operator_records_discharge_from_movement_screen(): void
    {
        [$shipment, $tank] = $this->setupCargo();
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageMovements::class)
            ->callAction('inbound', ['event_reference' => 'UI-1', 'shipment_id' => $shipment->id, 'tank_id' => $tank->id, 'quantity_litres' => '1000', 'occurred_at' => '2026-01-01 10:00:00'])
            ->assertHasNoActionErrors();
        $this->assertSame(995000, $tank->fresh()->balance_ml);
    }

    public function test_dashboard_presents_workflow_links_in_order(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk()->assertSeeInOrder([
            'Register shipment', 'Record discharge', 'View tank balances', 'Record delivery',
        ])->assertSee('/admin/shipments?action=create', false)
            ->assertSee('/admin/movements?action=inbound', false)
            ->assertSee('/admin/movements?action=outbound', false);
    }

    public function test_dashboard_action_links_open_the_correct_forms(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::withQueryParams(['action' => 'create'])->test(ManageShipments::class)->call('mountDefaultActions')->assertSet('mountedActions.0.name', 'create');
        Livewire::withQueryParams(['action' => 'inbound'])->test(ManageMovements::class)->call('mountDefaultActions')->assertSet('mountedActions.0.name', 'inbound');
        Livewire::withQueryParams(['action' => 'outbound'])->test(ManageMovements::class)->call('mountDefaultActions')->assertSet('mountedActions.0.name', 'outbound');
    }
}
