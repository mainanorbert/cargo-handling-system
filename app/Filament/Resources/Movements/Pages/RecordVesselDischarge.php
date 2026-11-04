<?php

namespace App\Filament\Resources\Movements\Pages;

class RecordVesselDischarge extends RecordMovement
{
    protected static ?string $title = 'Vessel Discharge (Inbound Cargo)';

    protected function getMovementType(): string
    {
        return 'inbound';
    }
}
