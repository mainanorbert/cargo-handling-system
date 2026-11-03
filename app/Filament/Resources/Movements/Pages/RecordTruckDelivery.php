<?php

namespace App\Filament\Resources\Movements\Pages;

class RecordTruckDelivery extends RecordMovement
{
    protected static ?string $title = 'Record truck delivery';

    protected function getMovementType(): string
    {
        return 'outbound';
    }
}
