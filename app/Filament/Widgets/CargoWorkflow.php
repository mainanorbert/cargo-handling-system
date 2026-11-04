<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Movements\MovementResource;
use App\Models\Commodity;
use App\Models\Movement;
use App\Models\Tank;
use Filament\Widgets\Widget;

class CargoWorkflow extends Widget
{
    protected static ?int $sort = -10;

    protected static ?bool $isLazyLoaded = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.cargo-workflow';

    protected function getViewData(): array
    {
        $commodityTotals = Tank::query()
            ->selectRaw('commodity_id, COUNT(*) as tank_count, COALESCE(SUM(capacity_ml), 0) as capacity_ml, COALESCE(SUM(balance_ml), 0) as balance_ml')
            ->whereNotNull('commodity_id')
            ->groupBy('commodity_id')
            ->get()
            ->keyBy('commodity_id');
        $commodities = Commodity::query()
            ->orderBy('code')
            ->get(['id', 'code', 'description'])
            ->map(function (Commodity $commodity) use ($commodityTotals): array {
                $totals = $commodityTotals->get($commodity->id);
                $capacityMl = (int) ($totals?->capacity_ml ?? 0);
                $balanceMl = (int) ($totals?->balance_ml ?? 0);

                return [
                    'code' => $commodity->code,
                    'description' => $commodity->description,
                    'tank_count' => (int) ($totals?->tank_count ?? 0),
                    'capacity_ml' => $capacityMl,
                    'balance_ml' => $balanceMl,
                    'available_ml' => $capacityMl - $balanceMl,
                ];
            })
            ->all();

        return [
            'commodities' => $commodities,
            'tanks' => Tank::query()->with('commodity:id,code')->orderBy('name')->limit(6)->get(),
            'recentMovements' => Movement::query()->with(['shipment:id,reference', 'truck:id,can_plate', 'tank:id,name', 'commodity:id,code'])->orderByDesc('occurred_at')->orderByDesc('event_reference')->limit(6)->get(),
            'movementUrl' => MovementResource::getUrl('vessel-discharge'),
        ];
    }
}
