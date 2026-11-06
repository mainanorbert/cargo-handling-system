<?php

namespace Tests\Feature;

use App\Exceptions\CargoException;
use App\Exceptions\ShipmentQuantityExceededException;
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
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
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
        $tank = Tank::create(['name' => 'A', 'capacity_ml' => 100000000]);
        $truck = Truck::create(['registration' => 'TRUCK', 'can_plate' => 'CAB TRUCK']);

        return [$shipment, $tank, $truck];
    }

    private function inbound(Shipment $shipment, Tank $tank, string $reference = 'E1', string $quantity = '100000', string $date = '2026-01-01 10:00:00'): Movement
    {
        return app(CargoService::class)->record(['type' => 'inbound', 'event_reference' => $reference, 'shipment_id' => $shipment->id, 'tank_id' => $tank->id, 'quantity_litres' => $quantity, 'occurred_at' => $date]);
    }

    public function test_inbound_discharge_and_delivery_update_stock_without_automatic_loss(): void
    {
        [$shipment, $tank, $truck] = $this->setupCargo();
        $movement = $this->inbound($shipment, $tank);
        $this->assertSame(0, $movement->loss_ml);
        $this->assertSame(100000000, $movement->quantity_ml);
        $this->assertSame(100000000, $tank->fresh()->balance_ml);
        app(CargoService::class)->record(['type' => 'outbound', 'event_reference' => 'E2', 'truck_id' => $truck->id, 'tank_id' => $tank->id, 'quantity_litres' => '500', 'occurred_at' => '2026-01-01 11:00:00']);
        $this->assertSame(99500000, $tank->fresh()->balance_ml);
    }

    public function test_shipment_can_be_split_across_tanks_without_exceeding_original_quantity_and_table_shows_allocated_tanks(): void
    {
        [$shipment, $tank] = $this->setupCargo();
        $shipment->update([
            'reference' => 'SHIP-DEMO-001',
            'expected_ml' => 1000000,
        ]);
        $tank->update(['capacity_ml' => 1000000]);
        $otherTank = Tank::create(['name' => 'B', 'capacity_ml' => 1000000]);

        $this->inbound($shipment, $tank, 'TANK-A', '900');

        try {
            $this->inbound($shipment, $otherTank, 'TANK-B-EXCESS', '400', '2026-01-01 11:00:00');
            $this->fail('Expected 900 L plus 400 L to exceed the shipment quantity.');
        } catch (ShipmentQuantityExceededException $exception) {
            $this->assertSame('Quantity cannot exceed the amount remaining in the selected shipment (100.000 L remains).', $exception->getMessage());
        }

        $this->assertSame(0, $otherTank->fresh()->balance_ml);
        $this->assertDatabaseCount('movements', 1);

        $this->inbound($shipment, $otherTank, 'TANK-B', '100', '2026-01-01 11:00:00');

        $this->assertSame(900000, $tank->fresh()->balance_ml);
        $this->assertSame(100000, $otherTank->fresh()->balance_ml);
        $this->assertSame(1000000, $shipment->dischargedMillilitres());
        $this->assertSame(0, $shipment->remainingMillilitres());
        $this->assertSame('100.00%', $shipment->formattedDischargePercentage());

        try {
            $this->inbound($shipment, $otherTank, 'EXCESS', '1', '2026-01-01 12:00:00');
            $this->fail('Expected the shipment quantity limit to be enforced.');
        } catch (ShipmentQuantityExceededException $exception) {
            $this->assertSame('Quantity cannot exceed the amount remaining in the selected shipment (0.000 L remains).', $exception->getMessage());
        }

        $this->assertSame(100000, $otherTank->fresh()->balance_ml);
        $this->assertDatabaseCount('movements', 2);

        $this->actingAs(User::factory()->create());

        $page = Livewire::test(RecordVesselDischarge::class)
            ->assertTableColumnExists('reference', fn (TextColumn $column): bool => $column->formatState($column->getState()) === 'SHIP-DE', $shipment)
            ->assertTableColumnExists('expected_ml', fn (TextColumn $column): bool => $column->getLabel() === 'Shipped Qty' && $column->getSize($column->getState()) === TextSize::ExtraSmall && $column->getWidth() === '17%', $shipment)
            ->assertTableColumnExists('discharged_ml', fn (TextColumn $column): bool => $column->getLabel() === 'Offloaded' && $column->getWidth() === '16%', $shipment)
            ->assertTableColumnExists('discharge_percentage', fn (TextColumn $column): bool => $column->getLabel() === 'Offloaded (%)' && $column->getWidth() === '13%', $shipment)
            ->assertTableColumnExists('unaccounted_percentage', fn (TextColumn $column): bool => $column->getLabel() === 'Unaccounted (%)' && $column->getWidth() === '16%', $shipment)
            ->assertTableColumnExists('allocations', fn (TextColumn $column): bool => $column->getLabel() === 'Tanks' && $column->getVisibleFrom() === null && $column->getWidth() === '20%', $shipment)
            ->assertTableColumnStateSet('allocations', 'A, B', $shipment)
            ->assertTableColumnStateSet('discharge_percentage', '100.00%', $shipment)
            ->assertTableColumnStateSet('unaccounted_percentage', '0.00%', $shipment)
            ->assertTableColumnExists('discharge_percentage', fn (TextColumn $column): bool => $column->getColor($column->getState()) === 'success' && $column->getTooltip($column->getState()) === 'Allowed range', $shipment)
            ->assertTableColumnDoesNotExist('expectation')
            ->mountAction(TestAction::make('view')->table($shipment))
            ->assertMountedActionModalSee('Shipment reference')
            ->assertMountedActionModalSee('SHIP-DEMO-001')
            ->assertMountedActionModalSee('Discharged quantity (L)')
            ->assertMountedActionModalSee('900.000')
            ->assertMountedActionModalSee('100.000');

        $this->assertTrue($page->instance()->getTable()->isStackedOnMobile());
        $this->assertSame('vessel-discharge-table', $page->instance()->getTable()->getExtraAttributes()['class']);
    }

    public function test_ninety_nine_point_five_percent_discharge_meets_expectation_while_lower_progress_does_not(): void
    {
        [$shipment, $tank] = $this->setupCargo();
        $shipment->update(['expected_ml' => 1000000]);

        $this->inbound($shipment, $tank, 'FIRST', '994');

        $this->assertSame('99.40%', $shipment->formattedDischargePercentage());
        $this->assertFalse($shipment->meetsDischargeExpectation());

        $this->inbound($shipment, $tank, 'SECOND', '1', '2026-01-01 11:00:00');

        $this->assertSame('99.50%', $shipment->formattedDischargePercentage());
        $this->assertTrue($shipment->meetsDischargeExpectation());
    }

    public function test_historical_discharge_above_one_hundred_percent_is_marked_outside_expectations(): void
    {
        [$shipment, $tank] = $this->setupCargo();
        $shipment->update(['expected_ml' => 1000000]);
        $tank->update([
            'capacity_ml' => 2000000,
            'balance_ml' => 1001000,
            'commodity_id' => $shipment->commodity_id,
        ]);
        Movement::create([
            'event_reference' => 'HISTORICAL-OVERAGE',
            'type' => 'inbound',
            'status' => 'applied',
            'tank_id' => $tank->id,
            'commodity_id' => $shipment->commodity_id,
            'shipment_id' => $shipment->id,
            'source_ml' => 1001000,
            'loss_ml' => 0,
            'quantity_ml' => 1001000,
            'loss_basis_points' => 0,
            'occurred_at' => '2026-01-01 10:00:00',
            'received_at' => '2026-01-01 10:00:00',
        ]);
        $this->actingAs(User::factory()->create());

        Livewire::test(RecordVesselDischarge::class)
            ->assertTableColumnStateSet('discharge_percentage', '100.10%', $shipment)
            ->assertTableColumnExists('discharge_percentage', fn (TextColumn $column): bool => $column->getColor($column->getState()) === 'warning' && $column->getTooltip($column->getState()) === 'Above allowed range', $shipment);
    }

    public function test_overflow_rolls_back(): void
    {
        [$shipment, $tank] = $this->setupCargo();
        $shipment->update(['expected_ml' => 101000000]);
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
        $this->assertSame(2000000, $tank->fresh()->balance_ml);
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
        $this->assertSame(1000000, $tank->fresh()->balance_ml);
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
            app(CargoService::class)->record(['type' => 'outbound', 'event_reference' => 'E3', 'truck_id' => $truck->id, 'tank_id' => $tank->id, 'quantity_litres' => '1001', 'occurred_at' => '2026-01-01 11:00:00']);
            $this->fail('Expected insufficient stock');
        } catch (CargoException) {
        }
        $this->assertSame(1000000, $tank->fresh()->balance_ml);
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
                'can_plate' => 'CAB 123A',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('trucks', [
            'can_plate' => 'CAB 123A',
            'trail_plate' => null,
        ]);
    }

    public function test_operator_cannot_register_a_truck_without_a_can_plate(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageTrucks::class)
            ->mountAction('create')
            ->setActionData([])
            ->callMountedAction()
            ->assertHasActionErrors(['can_plate' => 'required']);

        $this->assertDatabaseCount('trucks', 0);
    }

    public function test_operator_registers_a_shipment_with_a_short_generated_reference(): void
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
        $this->assertMatchesRegularExpression('/^SHIP-[A-Z]{3}-\d{3}$/', $shipment->reference);
    }

    public function test_operator_cannot_register_a_shipment_with_an_invalid_reference_format(): void
    {
        $commodity = Commodity::create(['code' => 'CPO', 'description' => 'Crude Palm Oil']);
        $vessel = Vessel::create(['name' => 'Vessel']);
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageShipments::class)
            ->mountAction('create')
            ->setActionData([
                'reference' => 'SHIP-DEMO-001',
                'origin' => 'Port of origin',
                'vessel_id' => $vessel->id,
                'commodity_id' => $commodity->id,
                'expected_ml' => '1000',
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['reference' => 'regex'])
            ->assertMountedActionModalSee('Shipment reference must use the format SHIP-ABC-123.');

        $this->assertDatabaseCount('shipments', 0);
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
        $operator = User::factory()->create();
        $this->actingAs($operator);

        $page = Livewire::test(RecordVesselDischarge::class)->mountAction('record');
        $reference = $page->get('eventReference');
        $this->assertTrue(Str::isUuid($reference));

        $page
            ->setActionData(['shipment_id' => $shipment->id])
            ->assertActionDataSet(['shipment_id' => $shipment->id, 'quantity_litres' => '100000.000']);

        $page
            ->setActionData(['shipment_id' => $shipment->id, 'tank_id' => $tank->id, 'quantity_litres' => '1000', 'occurred_at' => '2026-01-01 10:00:00'])
            ->assertMountedActionModalSee('Original quantity: 100,000.000 L; offloaded: 0.000 L (0.00%)')
            ->assertMountedActionModalSeeHtml('style="font-size: 0.6875rem; line-height: 1rem;"')
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertSame(1000000, $tank->fresh()->balance_ml);
        $this->assertDatabaseHas('movements', ['event_reference' => $reference, 'type' => 'inbound', 'loss_ml' => 0, 'quantity_ml' => 1000000, 'recorded_by_user_id' => $operator->id]);
    }

    public function test_discharge_form_shows_shipment_commodity_and_only_compatible_tanks(): void
    {
        [$shipment, $emptyTank] = $this->setupCargo();
        $compatibleTank = Tank::create(['name' => 'Compatible', 'capacity_ml' => 1000000]);
        $compatibleTank->forceFill(['commodity_id' => $shipment->commodity_id, 'balance_ml' => 500000])->save();
        $otherCommodity = Commodity::create(['code' => 'PFAD', 'description' => 'Palm Fatty Acid Distillate']);
        $incompatibleTank = Tank::create(['name' => 'Incompatible', 'capacity_ml' => 1000000]);
        $incompatibleTank->forceFill(['commodity_id' => $otherCommodity->id, 'balance_ml' => 500000])->save();
        $this->actingAs(User::factory()->create());

        Livewire::test(RecordVesselDischarge::class)
            ->mountAction('record')
            ->setActionData(['shipment_id' => $shipment->id])
            ->assertMountedActionModalSee('Shipment commodity')
            ->assertMountedActionModalSee('CPO — Crude Palm Oil')
            ->assertMountedActionModalSee('Tank compatibility')
            ->assertMountedActionModalSee('Incompatible — PFAD; 500.000 / 1,000.000 L; unavailable — contains a different commodity')
            ->assertMountedActionModalSee('Only empty tanks or tanks already holding CPO can receive this shipment.')
            ->assertSchemaComponentExists('tank_id', checkComponentUsing: fn (Select $component): bool => array_keys($component->getOptions()) === [$emptyTank->id, $compatibleTank->id]);
    }

    public function test_discharge_form_defaults_to_the_shipment_remaining_quantity_and_rejects_more(): void
    {
        [$shipment, $tank] = $this->setupCargo();
        $shipment->update(['expected_ml' => 1000000]);
        $this->inbound($shipment, $tank, 'TANK-A', '900');
        $otherTank = Tank::create(['name' => 'B', 'capacity_ml' => 1000000]);
        $this->actingAs(User::factory()->create());

        $page = Livewire::test(RecordVesselDischarge::class)
            ->mountAction('record')
            ->setActionData(['shipment_id' => $shipment->id]);

        $page
            ->assertActionDataSet(['shipment_id' => $shipment->id, 'quantity_litres' => '100.000'])
            ->assertMountedActionModalSee('Original quantity: 1,000.000 L; offloaded: 900.000 L (90.00%)')
            ->assertMountedActionModalDontSee('remaining:')
            ->assertMountedActionModalDontSee('After this entry:')
            ->setActionData(['tank_id' => $otherTank->id, 'quantity_litres' => '101', 'occurred_at' => '2026-01-01 11:00:00'])
            ->callMountedAction()
            ->assertHasActionErrors(['quantity_litres'])
            ->assertMountedActionModalSee('Quantity cannot exceed the amount remaining in the selected shipment.');

        $this->assertSame(0, $otherTank->fresh()->balance_ml);
        $this->assertDatabaseCount('movements', 1);
    }

    public function test_dashboard_presents_operational_statistics(): void
    {
        Commodity::create(['code' => 'CPO', 'description' => 'Crude Palm Oil']);
        Commodity::create(['code' => 'CPOL', 'description' => 'Crude Palm Olein']);
        Commodity::create(['code' => 'PFAD', 'description' => 'Palm Fatty Acid Distillate']);
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk()->assertSeeInOrder([
            'Commodities', 'Inbound shipments', 'Discharge standard met', 'Tanks', 'Trucks', 'Tank stock', 'Available capacity', 'Exceptions', 'Tank status', 'Recent movements',
        ])->assertDontSee('Registered shipments')
            ->assertDontSee('Inbound discharges')
            ->assertDontSee('Outbound deliveries')
            ->assertDontSee('Storage by commodity')
            ->assertDontSee('Follow the cargo journey');
    }

    public function test_dashboard_action_links_open_the_correct_forms(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::withQueryParams(['action' => 'create'])->test(ManageShipments::class)->call('mountDefaultActions')->assertSet('mountedActions.0.name', 'create');
        $this->get('/admin/movements/vessel-discharge')->assertOk()->assertSee('Inbound Cargo');
        Livewire::test(RecordVesselDischarge::class)->mountAction('record')->assertMountedActionModalSee('Discharged quantity (L)')->assertMountedActionModalSee('Inbound shipment');
        $this->get('/admin/movements/truck-delivery')->assertOk()->assertSee('Outbound Cargo');
        Livewire::test(RecordTruckDelivery::class)->mountAction('record')->assertMountedActionModalSee('Delivery quantity (L)')->assertMountedActionModalSee('Cab plate')->assertMountedActionModalSee('Trailer plate')->assertMountedActionModalSee('Commodity being transported');
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
            ->setActionData(['truck_id' => $truck->id, 'commodity_id' => $shipment->commodity_id, 'tank_id' => $tank->id, 'quantity_litres' => '500', 'occurred_at' => '2026-01-01 11:00:00'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(500000, $tank->fresh()->balance_ml);
        $this->assertDatabaseHas('movements', ['event_reference' => $reference, 'type' => 'outbound', 'loss_ml' => 0]);
    }

    public function test_delivery_tank_choices_follow_selected_commodity(): void
    {
        [$shipment, $tank] = $this->setupCargo();
        $this->inbound($shipment, $tank, 'E1', '1000');
        $otherCommodity = Commodity::create(['code' => 'PFAD', 'description' => 'Palm Fatty Acid Distillate']);
        $otherTank = Tank::create(['name' => 'Other tank', 'capacity_ml' => 1000000]);
        $otherTank->forceFill(['commodity_id' => $otherCommodity->id, 'balance_ml' => 100000])->save();
        $this->actingAs(User::factory()->create());

        Livewire::test(RecordTruckDelivery::class)
            ->mountAction('record')
            ->assertSchemaComponentExists('tank_id', checkComponentUsing: fn (Select $component): bool => $component->getOptions() === [])
            ->setActionData(['commodity_id' => $shipment->commodity_id])
            ->assertSchemaComponentExists('tank_id', checkComponentUsing: fn (Select $component): bool => array_keys($component->getOptions()) === [$tank->id])
            ->setActionData(['tank_id' => $tank->id])
            ->setActionData(['commodity_id' => $otherCommodity->id])
            ->assertSchemaComponentStateSet('tank_id', null)
            ->assertSchemaComponentExists('tank_id', checkComponentUsing: fn (Select $component): bool => array_keys($component->getOptions()) === [$otherTank->id]);
    }

    public function test_discharge_page_displays_overflow_error_without_changing_stock(): void
    {
        [$shipment, $tank] = $this->setupCargo();
        $shipment->update(['expected_ml' => 101000000]);
        $this->actingAs(User::factory()->create());

        $page = Livewire::test(RecordVesselDischarge::class)->mountAction('record');
        $reference = $page->get('eventReference');
        $this->assertTrue(Str::isUuid($reference));

        $page
            ->setActionData(['shipment_id' => $shipment->id, 'tank_id' => $tank->id, 'quantity_litres' => '100001', 'occurred_at' => '2026-01-01 10:00:00'])
            ->callMountedAction()
            ->assertHasActionErrors(['quantity_litres'])
            ->assertMountedActionModalSee('Quantity cannot exceed the available capacity of tank "A" (100,000.000 L available).')
            ->assertSet('eventReference', $reference);

        $this->assertSame(0, $tank->fresh()->balance_ml);
        $this->assertDatabaseCount('movements', 0);

        $page->setActionData(['quantity_litres' => '1000'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('movements', ['event_reference' => $reference, 'quantity_ml' => 1000000]);
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
            ->setActionData(['truck_id' => $truck->id, 'tank_id' => $tank->id, 'quantity_litres' => '1001', 'occurred_at' => '2026-01-01 11:00:00'])
            ->assertMountedActionModalSee('Delivery exceeds available tank stock of 1,000.000 L.')
            ->callMountedAction()
            ->assertHasActionErrors(['quantity_litres'])
            ->assertMountedActionModalSee('must not be greater than 1000.000');

        $this->assertSame(1000000, $tank->fresh()->balance_ml);
        $this->assertDatabaseCount('movements', 1);
    }

    public function test_delivery_quantity_is_limited_to_the_selected_tank_balance(): void
    {
        [$shipment, $tank, $truck] = $this->setupCargo();
        $this->inbound($shipment, $tank, 'E1', '1000');
        $this->actingAs(User::factory()->create());

        $page = Livewire::test(RecordTruckDelivery::class)
            ->mountAction('record')
            ->setActionData(['tank_id' => $tank->id])
            ->assertSchemaComponentExists('quantity_litres', checkComponentUsing: fn (TextInput $component): bool => $component->getMaxValue() === '1000.000');

        $page
            ->setActionData(['truck_id' => $truck->id, 'quantity_litres' => '1000.001', 'occurred_at' => '2026-01-01 11:00:00'])
            ->callMountedAction()
            ->assertHasActionErrors(['quantity_litres']);

        $this->assertSame(1000000, $tank->fresh()->balance_ml);
        $this->assertDatabaseCount('movements', 1);
    }

    public function test_movement_pages_require_login(): void
    {
        $this->get('/admin/movements/vessel-discharge')->assertRedirect('/admin/login');
        $this->get('/admin/movements/truck-delivery')->assertRedirect('/admin/login');
    }

    public function test_discharge_page_has_one_shipment_row_and_delivery_page_paginates_movements(): void
    {
        [$shipment, $tank, $truck] = $this->setupCargo();
        $events = [];
        for ($index = 1; $index <= 12; $index++) {
            $events[] = ['type' => 'inbound', 'event_reference' => sprintf('IN-%02d', $index), 'shipment_id' => $shipment->id, 'tank_id' => $tank->id, 'quantity_litres' => '100', 'occurred_at' => '2026-01-01 10:00:00'];
            $events[] = ['type' => 'outbound', 'event_reference' => sprintf('OUT-%02d', $index), 'truck_id' => $truck->id, 'tank_id' => $tank->id, 'quantity_litres' => '10', 'occurred_at' => '2026-01-01 11:00:00'];
        }
        app(CargoService::class)->recordBatch($events);
        $this->actingAs(User::factory()->create());

        Livewire::test(RecordVesselDischarge::class)
            ->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([$shipment])
            ->assertTableColumnStateSet('discharged_ml', 1200000, $shipment)
            ->assertTableColumnStateSet('discharge_percentage', '1.20%', $shipment)
            ->assertTableColumnExists('discharge_percentage', fn (TextColumn $column): bool => $column->getColor($column->getState()) === 'danger' && $column->getTooltip($column->getState()) === 'Below allowed range', $shipment)
            ->assertTableColumnStateSet('allocations', 'A', $shipment)
            ->assertTableColumnDoesNotExist('expectation');

        $records = Movement::where('type', 'outbound')->orderByDesc('event_reference')->get();
        $otherRecords = Movement::where('type', 'inbound')->get();
        $page = Livewire::test(RecordTruckDelivery::class)
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

    public function test_movement_tables_show_details_in_a_view_modal(): void
    {
        [$shipment, $tank, $truck] = $this->setupCargo();
        $inbound = $this->inbound($shipment, $tank, 'INBOUND', '1000');
        $outbound = app(CargoService::class)->record(['type' => 'outbound', 'event_reference' => 'OUTBOUND', 'truck_id' => $truck->id, 'tank_id' => $tank->id, 'quantity_litres' => '500', 'occurred_at' => '2026-01-01 11:00:00']);
        $this->actingAs(User::factory()->create());

        Livewire::test(RecordVesselDischarge::class)
            ->assertTableColumnExists('discharge_percentage')
            ->assertTableColumnDoesNotExist('expectation')
            ->assertActionExists(TestAction::make('view')->table($shipment))
            ->mountAction(TestAction::make('view')->table($shipment))
            ->assertMountedActionModalSee('discharge details')
            ->assertMountedActionModalSee('Tank discharge history')
            ->assertMountedActionModalSee('INBOUND');

        Livewire::test(RecordTruckDelivery::class)
            ->assertTableColumnDoesNotExist('event_reference')
            ->assertTableColumnExists('shipment_or_truck')
            ->assertActionExists(TestAction::make('view')->table($outbound))
            ->mountAction(TestAction::make('view')->table($outbound))
            ->assertMountedActionModalSee('Movement details')
            ->assertMountedActionModalSee('Source quantity (L)')
            ->assertMountedActionModalSee('Loss rate')
            ->assertMountedActionModalSee('Recorded at');
    }
}
