<?php

namespace App\Observers;

use App\Models\Movement;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class MovementObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Movement $movement): void
    {
        Log::info('Cargo movement recorded', ['movement_id' => $movement->id, 'event_reference' => $movement->event_reference, 'status' => $movement->status]);
    }
}
