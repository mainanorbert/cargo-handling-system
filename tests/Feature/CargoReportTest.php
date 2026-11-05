<?php

namespace Tests\Feature;

use App\Filament\Resources\Reports\Pages\ListMovementReports;
use App\Filament\Resources\Reports\Pages\ListOutboundReports;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Models\AuditEntry;
use App\Models\Commodity;
use App\Models\Movement;
use App\Models\Shipment;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\User;
use App\Models\Vessel;
use App\Services\CargoService;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CargoReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{Movement, Movement, Movement} */
    private function movements(): array
    {
        $commodity = Commodity::factory()->create(['code' => 'CPO', 'description' => 'Crude Palm Oil']);
        $vessel = Vessel::factory()->create(['name' => 'Vessel Alpha']);
        $shipment = Shipment::factory()->create(['reference' => 'SHIP-1', 'origin' => 'Origin', 'vessel_id' => $vessel->id, 'commodity_id' => $commodity->id, 'expected_ml' => 1000000]);
        $tank = Tank::factory()->create(['name' => 'Tank A', 'capacity_ml' => 1000000]);
        $truck = Truck::factory()->create(['can_plate' => 'CAB-1']);
        $service = app(CargoService::class);

        $inbound = $service->record(['type' => 'inbound', 'event_reference' => 'IN-1', 'shipment_id' => $shipment->id, 'tank_id' => $tank->id, 'quantity_litres' => '500.125', 'occurred_at' => '2026-01-01 10:00:00']);
        $outbound = $service->record(['type' => 'outbound', 'event_reference' => 'OUT-1', 'truck_id' => $truck->id, 'tank_id' => $tank->id, 'quantity_litres' => '100', 'occurred_at' => '2026-01-01 11:00:00']);
        $later = $service->record(['type' => 'outbound', 'event_reference' => 'OUT-2', 'truck_id' => $truck->id, 'tank_id' => $tank->id, 'quantity_litres' => '10', 'occurred_at' => '2026-01-01 11:00:01']);

        return [$inbound, $outbound, $later];
    }

    public function test_reports_require_authentication(): void
    {
        $this->get('/admin/reports')->assertRedirect('/admin/login');
    }

    public function test_reports_filter_stage_and_inclusive_event_time_and_show_cargo_details(): void
    {
        [$inbound, $outbound, $later] = $this->movements();
        $this->actingAs(User::factory()->create());

        Livewire::test(ListMovementReports::class)
            ->filterTable('type', 'inbound')
            ->assertCanSeeTableRecords([$inbound])
            ->assertCanNotSeeTableRecords([$outbound, $later])
            ->assertSee('Vessel Alpha')
            ->assertSee('SHIP-1')
            ->assertSee('Tank A')
            ->assertSee('500.125')
            ->filterTable('type', 'outbound')
            ->filterTable('event_time', ['from' => '2026-01-01 11:00:01', 'until' => null])
            ->assertCanSeeTableRecords([$later])
            ->assertCanNotSeeTableRecords([$outbound])
            ->filterTable('event_time', ['from' => null, 'until' => '2026-01-01 11:00:00'])
            ->assertCanSeeTableRecords([$outbound])
            ->assertCanNotSeeTableRecords([$later])
            ->filterTable('event_time', ['from' => '2026-01-01 11:00:00', 'until' => '2026-01-01 11:00:00'])
            ->assertCanSeeTableRecords([$outbound])
            ->assertCanNotSeeTableRecords([$inbound, $later])
            ->assertSee('CAB-1');
    }

    public function test_csv_download_contains_only_filtered_movements(): void
    {
        [$inbound] = $this->movements();
        $this->actingAs(User::factory()->create());

        $page = Livewire::test(ListMovementReports::class)
            ->filterTable('type', 'inbound')
            ->callAction('download')
            ->assertFileDownloaded('cargo-report.csv');

        $csv = base64_decode(data_get($page->effects, 'download.content'));
        $this->assertStringContainsString('IN-1,SHIP-1,"Vessel Alpha"', $csv);
        $this->assertStringContainsString('500.125', $csv);
        $this->assertStringNotContainsString('OUT-1', $csv);
    }

    public function test_csv_download_neutralizes_spreadsheet_formulas(): void
    {
        [$inbound] = $this->movements();
        $inbound->shipment->vessel->update(['name' => '=1+1']);
        $this->actingAs(User::factory()->create());

        $page = Livewire::test(ListMovementReports::class)->filterTable('type', 'inbound')->callAction('download');

        $csv = base64_decode(data_get($page->effects, 'download.content'));
        $this->assertStringContainsString("'=1+1", $csv);
    }

    public function test_inbound_reports_group_receipts_by_shipment_and_include_details_in_print_view(): void
    {
        [$inbound] = $this->movements();
        $shipment = $inbound->shipment;
        $this->actingAs(User::factory()->create());
        app(CargoService::class)->record(['type' => 'inbound', 'event_reference' => 'IN-2', 'shipment_id' => $shipment->id, 'tank_id' => $inbound->tank_id, 'quantity_litres' => '100', 'occurred_at' => '2026-01-01 12:00:00']);

        Livewire::test(ListReports::class)
            ->assertCanSeeTableRecords([$shipment])
            ->assertSee('600.125')
            ->assertSee('60.01%')
            ->assertDontSee('IN-1');

        $this->get(route('inbound-reports.show', $shipment))->assertOk()
            ->assertSee('SHIP-1')->assertSee('IN-1')->assertSee('IN-2')
            ->assertSee('600.125')->assertSee('60.01%');
    }

    public function test_filtered_inbound_report_only_includes_matching_shipments(): void
    {
        [$inbound] = $this->movements();
        $other = Shipment::factory()->create(['reference' => 'OTHER', 'origin' => 'Elsewhere', 'vessel_id' => Vessel::factory()->create(['name' => 'Other Vessel'])->id, 'commodity_id' => $inbound->commodity_id, 'expected_ml' => 1000000]);
        $this->actingAs(User::factory()->create());

        Livewire::test(ListReports::class)
            ->filterTable('vessel', $inbound->shipment->vessel_id)
            ->assertCanSeeTableRecords([$inbound->shipment])
            ->assertCanNotSeeTableRecords([$other])
            ->callAction('report');

        $this->get(route('inbound-reports.filtered'))->assertOk()->assertSee('SHIP-1')->assertDontSee('OTHER');
    }

    public function test_inbound_reports_filter_shipments_by_inclusive_event_time(): void
    {
        [$inbound] = $this->movements();
        $laterShipment = Shipment::factory()->create([
            'reference' => 'SHIP-LATER',
            'origin' => 'Later origin',
            'vessel_id' => $inbound->shipment->vessel_id,
            'commodity_id' => $inbound->commodity_id,
            'expected_ml' => 1000000,
        ]);
        app(CargoService::class)->record([
            'type' => 'inbound',
            'event_reference' => 'IN-LATER',
            'shipment_id' => $laterShipment->id,
            'tank_id' => $inbound->tank_id,
            'quantity_litres' => '10',
            'occurred_at' => '2026-01-01 12:00:00',
        ]);

        $this->actingAs(User::factory()->create());

        Livewire::test(ListReports::class)
            ->filterTable('event_time', ['from' => '2026-01-01 10:00:00', 'until' => '2026-01-01 10:00:00'])
            ->assertCanSeeTableRecords([$inbound->shipment])
            ->assertCanNotSeeTableRecords([$laterShipment]);
    }

    public function test_inbound_report_requires_authentication(): void
    {
        [$inbound] = $this->movements();
        $this->get(route('inbound-reports.show', $inbound->shipment))->assertRedirect('/admin/login');
    }

    public function test_outbound_reports_filter_deliveries_and_print_only_filtered_results(): void
    {
        [$inbound, $outbound, $later] = $this->movements();
        $this->actingAs(User::factory()->create());

        Livewire::test(ListOutboundReports::class)
            ->assertCanSeeTableRecords([$outbound, $later])
            ->assertCanNotSeeTableRecords([$inbound])
            ->filterTable('commodity', $outbound->commodity_id)
            ->filterTable('event_time', ['from' => null, 'until' => '2026-01-01 11:00:00'])
            ->assertCanSeeTableRecords([$outbound])
            ->assertCanNotSeeTableRecords([$later])
            ->callAction('report');

        $this->get(route('outbound-reports.filtered'))->assertOk()
            ->assertSee('OUT-1')->assertSee('100.000')->assertDontSee('OUT-2')->assertDontSee('IN-1');
        $this->get(route('outbound-reports.show', $outbound))->assertOk()->assertSee('OUT-1')->assertDontSee('OUT-2');
        $this->get(route('outbound-reports.show', $inbound))->assertNotFound();
    }

    public function test_report_generation_records_actor_scope_and_included_records(): void
    {
        [$inbound, $outbound] = $this->movements();
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('inbound-reports.show', $inbound->shipment))->assertOk();
        $entry = AuditEntry::where('subject_type', 'Inbound report')->where('action', 'generated')->sole();
        $this->assertSame($user->id, $entry->actor_id);
        $this->assertSame([$inbound->shipment_id], $entry->new_values['record_ids']);
        $this->assertSame('single shipment', $entry->new_values['criteria']['scope']);

        $this->get(route('outbound-reports.show', $outbound))->assertOk();
        $entry = AuditEntry::where('subject_type', 'Outbound report')->where('action', 'generated')->sole();
        $this->assertSame([$outbound->id], $entry->new_values['record_ids']);
        $this->assertSame($user->name, $entry->actor_name);
    }

    public function test_received_percentage_badge_uses_shipment_progress_thresholds(): void
    {
        [$inbound] = $this->movements();
        $shipment = $inbound->shipment;
        $this->actingAs(User::factory()->create());

        Livewire::test(ListReports::class)
            ->assertTableColumnExists('received_percentage', fn (TextColumn $column): bool => $column->getColor($column->getState()) === 'danger' && $column->getTooltip($column->getState()) === 'Below allowed range', $shipment);

        $inbound->update(['quantity_ml' => 995000]);
        Livewire::test(ListReports::class)
            ->assertTableColumnExists('received_percentage', fn (TextColumn $column): bool => $column->getColor($column->getState()) === 'success' && $column->getTooltip($column->getState()) === 'Allowed range', $shipment);

        $inbound->update(['quantity_ml' => 1001000]);
        Livewire::test(ListReports::class)
            ->assertTableColumnExists('received_percentage', fn (TextColumn $column): bool => $column->getColor($column->getState()) === 'warning' && $column->getTooltip($column->getState()) === 'Above allowed range', $shipment);
    }
}
