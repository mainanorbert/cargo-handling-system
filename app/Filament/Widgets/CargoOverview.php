<?php

namespace App\Filament\Widgets;

use App\Models\Movement;
use App\Models\Tank;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CargoOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $stats = [Stat::make('Total stock', number_format(Tank::sum('balance_ml') / 1000, 3).' L'), Stat::make('Late events needing review', Movement::where('status', 'needs_review')->count())];
        foreach (Tank::with('commodity')->get() as $tank) {
            $stats[] = Stat::make($tank->name, number_format($tank->balance_ml / 1000, 3).' L')->description(($tank->commodity?->code ?? 'Empty').' · '.number_format($tank->balance_ml * 100 / $tank->capacity_ml, 1).'% used');
        }

        return $stats;
    }
}
