<?php

namespace Tests\Feature;

use App\Filament\Resources\Reports\Pages\ListReports;
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

        Livewire::test(ListReports::class)
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

        $page = Livewire::test(ListReports::class)
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

        $page = Livewire::test(ListReports::class)->filterTable('type', 'inbound')->callAction('download');

        $csv = base64_decode(data_get($page->effects, 'download.content'));
        $this->assertStringContainsString("'=1+1", $csv);
    }
}
