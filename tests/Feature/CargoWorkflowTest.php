<?php

namespace Tests\Feature;

use App\Exceptions\CargoException;
use App\Exceptions\TankCapacityException;
use App\Filament\Resources\Movements\Pages\RecordTruckDelivery;
use App\Filament\Resources\Movements\Pages\RecordVesselDischarge;
use App\Filament\Resources\Shipments\Pages\ManageShipments;
use App\Filament\Resources\Trucks\Pages\ManageTrucks;
use App\Models\Commodity;
use App\Models\Movement;
use App\Models\Shipment;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\User;
use App\Models\Vessel;
use App\Services\CargoService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
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
        $truck = Truck::create(['registration' => 'TRUCK', 'can_plate' => 'CAB TRUCK']);

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

    public function test_late_event_is_replayed_without_rewriting_history(): void
    {
        [$shipment, $tank] = $this->setupCargo();
        $this->inbound($shipment, $tank, 'B', '1000');
        $late = $this->inbound($shipment, $tank, 'A', '1000');
        $this->assertSame('applied', $late->status);
        $this->assertSame(1990000, $tank->fresh()->balance_ml);
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

    public function test_authenticated_operator_can_open_direct_movement_screens_without_a_generic_movement_index(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['/admin', '/admin/shipments', '/admin/tanks', '/admin/movements/vessel-discharge', '/admin/movements/truck-delivery'] as $path) {
            $this->get($path)->assertOk();
        }

        $this->get('/admin/movements')->assertNotFound();
    }

    public function test_operator_registers_a_truck_without_a_trail_plate(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageTrucks::class)
            ->mountAction('create')
            ->setActionData([
                'registration' => 'KAA 123A',
                'can_plate' => 'CAB 123A',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('trucks', [
            'registration' => 'KAA 123A',
            'can_plate' => 'CAB 123A',
            'trail_plate' => null,
        ]);
    }

    public function test_operator_cannot_register_a_truck_without_a_can_plate(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageTrucks::class)
            ->mountAction('create')
            ->setActionData(['registration' => 'KAA 123A'])
            ->callMountedAction()
            ->assertHasActionErrors(['can_plate' => 'required']);

        $this->assertDatabaseMissing('trucks', ['registration' => 'KAA 123A']);
    }

    public function test_operator_registers_a_shipment_with_a_generated_unique_reference(): void
    {
        $commodity = Commodity::create(['code' => 'CPO', 'description' => 'Crude Palm Oil']);
        $vessel = Vessel::create(['name' => 'Vessel']);
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageShipments::class)
            ->mountAction('create')
            ->setActionData([
                'origin' => 'Port of origin',
                'vessel_id' => $vessel->id,
                'commodity_id' => $commodity->id,
                'expected_ml' => '1000',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $shipment = Shipment::where('origin', 'Port of origin')->sole();
        $this->assertTrue(Str::isUuid(Str::after($shipment->reference, 'SHIP-')));
    }

    public function test_logged_in_operator_sees_an_operations_link_instead_of_an_admin_login_link(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertDontSee('Admin login')
            ->assertSee('Open operations');
    }

    public function test_operator_records_discharge_from_movement_screen(): void
    {
        [$shipment, $tank] = $this->setupCargo();
        $this->actingAs(User::factory()->create());

        $page = Livewire::test(RecordVesselDischarge::class)->mountAction('record');
        $reference = $page->get('eventReference');
        $this->assertTrue(Str::isUuid($reference));

        $page
            ->setActionData(['shipment_id' => $shipment->id, 'tank_id' => $tank->id, 'quantity_litres' => '1000', 'occurred_at' => '2026-01-01 10:00:00'])
            ->assertMountedActionModalSee('Shipment quantity: 100,000.000 L. Actual received after standard 0.5% loss: 995.000 L.')
            ->assertMountedActionModalSee('Estimated loss: 5.000 L; projected balance: 995.000 L.')
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertSame(995000, $tank->fresh()->balance_ml);
        $this->assertDatabaseHas('movements', ['event_reference' => $reference, 'type' => 'inbound', 'loss_ml' => 5000]);
    }

    public function test_dashboard_presents_workflow_links_in_order(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk()->assertSeeInOrder([
            'Register shipment', 'Vessel Discharge (Inbound Cargo)', 'Shore Storage Tanks', 'Truck Delivery (Outbound Cargo)',
        ])->assertSee('/admin/shipments?action=create', false)
            ->assertSee('/admin/movements/vessel-discharge', false)
            ->assertSee('/admin/movements/truck-delivery', false);
    }

    public function test_dashboard_action_links_open_the_correct_forms(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::withQueryParams(['action' => 'create'])->test(ManageShipments::class)->call('mountDefaultActions')->assertSet('mountedActions.0.name', 'create');
        $this->get('/admin/movements/vessel-discharge')->assertOk()->assertSee('Vessel Discharge (Inbound Cargo)');
        Livewire::test(RecordVesselDischarge::class)->mountAction('record')->assertMountedActionModalSee('Discharged quantity (L)')->assertMountedActionModalSee('Inbound shipment');
        $this->get('/admin/movements/truck-delivery')->assertOk()->assertSee('Truck Delivery (Outbound Cargo)');
        Livewire::test(RecordTruckDelivery::class)->mountAction('record')->assertMountedActionModalSee('Delivery quantity (L)');
    }

    public function test_operator_records_delivery_from_its_page(): void
    {
        [$shipment, $tank, $truck] = $this->setupCargo();
        $this->inbound($shipment, $tank, 'E1', '1000');
        $this->actingAs(User::factory()->create());

        $page = Livewire::test(RecordTruckDelivery::class)->mountAction('record');
        $reference = $page->get('eventReference');
        $this->assertTrue(Str::isUuid($reference));

        $page
            ->setActionData(['truck_id' => $truck->id, 'tank_id' => $tank->id, 'quantity_litres' => '500', 'occurred_at' => '2026-01-01 11:00:00'])
            ->assertMountedActionModalSee('Estimated loss: 0.000 L; projected balance: 495.000 L.')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(495000, $tank->fresh()->balance_ml);
        $this->assertDatabaseHas('movements', ['event_reference' => $reference, 'type' => 'outbound', 'loss_ml' => 0]);
    }

    public function test_discharge_page_displays_overflow_error_without_changing_stock(): void
    {
        [$shipment, $tank] = $this->setupCargo();
        $this->actingAs(User::factory()->create());

        $page = Livewire::test(RecordVesselDischarge::class)->mountAction('record');
        $reference = $page->get('eventReference');
        $this->assertTrue(Str::isUuid($reference));

        $page
            ->setActionData(['shipment_id' => $shipment->id, 'tank_id' => $tank->id, 'quantity_litres' => '100001', 'occurred_at' => '2026-01-01 10:00:00'])
            ->callMountedAction()
            ->assertHasActionErrors(['quantity_litres'])
            ->assertMountedActionModalSee('The received quantity exceeds the tank capacity.')
            ->assertSet('eventReference', $reference);

        $this->assertSame(0, $tank->fresh()->balance_ml);
        $this->assertDatabaseCount('movements', 0);

        $page->setActionData(['quantity_litres' => '1000'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('movements', ['event_reference' => $reference, 'quantity_ml' => 995000]);
        $this->assertDatabaseCount('movements', 1);
    }

    public function test_delivery_page_displays_insufficient_stock_error(): void
    {
        [$shipment, $tank, $truck] = $this->setupCargo();
        $this->inbound($shipment, $tank, 'E1', '1000');
        $this->actingAs(User::factory()->create());

        $page = Livewire::test(RecordTruckDelivery::class)->mountAction('record');
        $reference = $page->get('eventReference');
        $this->assertTrue(Str::isUuid($reference));

        $page
            ->setActionData(['truck_id' => $truck->id, 'tank_id' => $tank->id, 'quantity_litres' => '996', 'occurred_at' => '2026-01-01 11:00:00'])
            ->callMountedAction()
            ->assertHasActionErrors(['quantity_litres'])
            ->assertMountedActionModalSee('The delivery exceeds the available stock.');

        $this->assertSame(995000, $tank->fresh()->balance_ml);
        $this->assertDatabaseCount('movements', 1);
    }

    public function test_movement_pages_require_login(): void
    {
        $this->get('/admin/movements/vessel-discharge')->assertRedirect('/admin/login');
        $this->get('/admin/movements/truck-delivery')->assertRedirect('/admin/login');
    }

    public function test_each_movement_page_filters_and_paginates_its_records(): void
    {
        [$shipment, $tank, $truck] = $this->setupCargo();
        $events = [];
        for ($index = 1; $index <= 12; $index++) {
            $events[] = ['type' => 'inbound', 'event_reference' => sprintf('IN-%02d', $index), 'shipment_id' => $shipment->id, 'tank_id' => $tank->id, 'quantity_litres' => '100', 'occurred_at' => '2026-01-01 10:00:00'];
            $events[] = ['type' => 'outbound', 'event_reference' => sprintf('OUT-%02d', $index), 'truck_id' => $truck->id, 'tank_id' => $tank->id, 'quantity_litres' => '10', 'occurred_at' => '2026-01-01 11:00:00'];
        }
        app(CargoService::class)->recordBatch($events);
        $this->actingAs(User::factory()->create());

        foreach ([RecordVesselDischarge::class => 'inbound', RecordTruckDelivery::class => 'outbound'] as $pageClass => $type) {
            $records = Movement::where('type', $type)->orderByDesc('event_reference')->get();
            $otherRecords = Movement::where('type', '!=', $type)->get();
            $page = Livewire::test($pageClass)
                ->assertCanSeeTableRecords($records->take(10))
                ->assertCanNotSeeTableRecords($records->slice(10))
                ->assertCanNotSeeTableRecords($otherRecords);

            $page->call('gotoPage', 2)
                ->assertCanSeeTableRecords($records->slice(10))
                ->assertCanNotSeeTableRecords($records->take(10));

            $page->set('tableRecordsPerPage', 25)
                ->assertCanSeeTableRecords($records)
                ->assertCanNotSeeTableRecords($otherRecords);
        }
    }
}
