<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Movements\MovementResource;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Filament\Resources\Tanks\TankResource;
use Filament\Widgets\Widget;

class CargoWorkflow extends Widget
{
    protected static ?int $sort = -10;

    protected static ?bool $isLazyLoaded = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.cargo-workflow';

    protected function getViewData(): array
    {
        return [
            'shipmentUrl' => ShipmentResource::getUrl('index', ['action' => 'create']),
            'dischargeUrl' => MovementResource::getUrl('vessel-discharge'),
            'tankUrl' => TankResource::getUrl(),
            'deliveryUrl' => MovementResource::getUrl('truck-delivery'),
        ];
    }
}
