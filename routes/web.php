<?php

use App\Filament\Resources\Reports\OutboundReportResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\Movement;
use App\Models\Shipment;
use App\Services\AuditService;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/inbound-reports/filtered', function () {
        abort_unless(ReportResource::canViewAny(), 403);
        $ids = session('inbound_report_shipment_ids', []);
        $shipments = Shipment::query()->whereIn('id', $ids)
            ->with(['vessel', 'commodity', 'inboundMovements.tank'])->orderBy('id')->get();

        app(AuditService::class)->reportGenerated('Inbound report', $shipments, session('inbound_report_criteria', []));

        return view('filament.inbound-report', compact('shipments'));
    })->name('inbound-reports.filtered');

    Route::get('/inbound-reports/{shipment}', function (Shipment $shipment) {
        abort_unless(ReportResource::canViewAny(), 403);
        $shipments = collect([$shipment->load(['vessel', 'commodity', 'inboundMovements.tank'])]);

        app(AuditService::class)->reportGenerated('Inbound report', $shipments, ['scope' => 'single shipment']);

        return view('filament.inbound-report', compact('shipments'));
    })->name('inbound-reports.show');

    Route::get('/outbound-reports/filtered', function () {
        abort_unless(OutboundReportResource::canViewAny(), 403);
        $movements = Movement::query()->where('type', 'outbound')->whereIn('id', session('outbound_report_movement_ids', []))
            ->with(['truck', 'tank', 'commodity'])->orderBy('occurred_at')->orderBy('event_reference')->get();

        app(AuditService::class)->reportGenerated('Outbound report', $movements, session('outbound_report_criteria', []));

        return view('filament.outbound-report', compact('movements'));
    })->name('outbound-reports.filtered');

    Route::get('/outbound-reports/{movement}', function (Movement $movement) {
        abort_unless(OutboundReportResource::canViewAny(), 403);
        abort_unless($movement->type === 'outbound', 404);
        $movements = collect([$movement->load(['truck', 'tank', 'commodity'])]);

        app(AuditService::class)->reportGenerated('Outbound report', $movements, ['scope' => 'single delivery']);

        return view('filament.outbound-report', compact('movements'));
    })->name('outbound-reports.show');

});
