<?php

namespace App\Filament\Resources\Movements\Pages;

use App\Filament\Resources\Movements\MovementResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;

class ManageMovements extends ManageRecords
{
    protected static string $resource = MovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('inbound')->label('Record vessel discharge')->url(MovementResource::getUrl('vessel-discharge')),
            Action::make('outbound')->label('Record truck delivery')->url(MovementResource::getUrl('truck-delivery')),
        ];
    }
}
