<?php

namespace App\Filament\Resources\Movements\Pages;

class RecordTruckDelivery extends RecordMovement
{
    protected static ?string $title = 'Outbound Cargo';

    protected function getMovementType(): string
    {
        return 'outbound';
    }
}
