<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Movements\MovementResource;
use App\Models\Commodity;
use App\Models\Movement;
use App\Models\Shipment;
use App\Models\Tank;
use App\Models\Truck;
use Filament\Widgets\Widget;

class CargoWorkflow extends Widget
{
    protected static ?int $sort = -10;

    protected static ?bool $isLazyLoaded = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.cargo-workflow';

    protected function getViewData(): array
    {
        $totals = Tank::query()
            ->selectRaw('COALESCE(SUM(capacity_ml), 0) as capacity_ml, COALESCE(SUM(balance_ml), 0) as balance_ml')
            ->firstOrFail();
        $capacityMl = (int) $totals->capacity_ml;
        $balanceMl = (int) $totals->balance_ml;
        $inboundShipments = Shipment::query()
            ->withSum('inboundMovements as discharged_ml', 'source_ml')
            ->get(['id', 'expected_ml']);
        $inboundShipmentCount = $inboundShipments->count();
        $shipmentsMeetingStandardCount = $inboundShipments
            ->filter(fn (Shipment $shipment): bool => $shipment->meetsDischargeExpectation())
            ->count();

        return [
            'availableMl' => $capacityMl - $balanceMl,
            'balanceMl' => $balanceMl,
            'commodityCount' => Commodity::count(),
            'exceptionsNeedingReview' => Movement::query()->where('status', 'needs_review')->count(),
            'inboundShipmentCount' => $inboundShipmentCount,
            'shipmentsMeetingStandardCount' => $shipmentsMeetingStandardCount,
            'shipmentStandardMetPercentageBasisPoints' => Shipment::percentageBasisPoints($shipmentsMeetingStandardCount, $inboundShipmentCount),
            'tankCount' => Tank::count(),
            'tanks' => Tank::query()->with('commodity:id,code')->orderBy('name')->limit(6)->get(),
            'recentMovements' => Movement::query()->with(['shipment:id,reference', 'truck:id,can_plate', 'tank:id,name', 'commodity:id,code'])->orderByDesc('occurred_at')->orderByDesc('event_reference')->limit(6)->get(),
            'truckCount' => Truck::count(),
            'movementUrl' => MovementResource::getUrl('vessel-discharge'),
        ];
    }
}
