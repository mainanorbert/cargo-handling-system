<?php

namespace App\Filament\Resources\Movements\Pages;

class RecordVesselDischarge extends RecordMovement
{
    protected static ?string $title = 'Record vessel discharge';

    protected function getMovementType(): string
    {
        return 'inbound';
    }
}
