<?php

namespace App\Filament\Widgets;

use App\Models\Movement;
use App\Models\Shipment;
use App\Models\Tank;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CargoOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -20;

    protected function getStats(): array
    {
        $totals = Tank::query()
            ->selectRaw('COALESCE(SUM(capacity_ml), 0) as capacity_ml, COALESCE(SUM(balance_ml), 0) as balance_ml')
            ->firstOrFail();
        $capacityMl = (int) $totals->capacity_ml;
        $balanceMl = (int) $totals->balance_ml;

        return [
            Stat::make('Registered shipments', Shipment::count()),
            Stat::make('Inbound discharges', Movement::where('type', 'inbound')->count()),
            Stat::make('Outbound deliveries', Movement::where('type', 'outbound')->count()),
            Stat::make('Total tank capacity', number_format($capacityMl / 1000, 3).' L'),
            Stat::make('Current tank stock', number_format($balanceMl / 1000, 3).' L'),
            Stat::make('Available capacity', number_format(($capacityMl - $balanceMl) / 1000, 3).' L'),
            Stat::make('Exceptions needing review', Movement::where('status', 'needs_review')->count()),
        ];
    }
}
