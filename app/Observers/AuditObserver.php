<?php

namespace App\Observers;

use App\Models\AuditEntry;
use App\Models\Movement;
use App\Models\Shipment;
use App\Models\Tank;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class AuditObserver
{
    public function created(Model $model): void
    {
        $this->record($model, 'created', [], $this->values($model, $model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $new = $this->values($model, $model->getChanges());
        if ($model instanceof User && array_key_exists('password', $model->getChanges())) {
            $new['password_changed'] = true;
        }
        if ($new === []) {
            return;
        }
        $this->record($model, 'updated', Arr::only($model->getRawOriginal(), array_keys($new)), $new);
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', $this->values($model, $model->getAttributes()), []);
    }

    private function values(Model $model, array $values): array
    {
        $allowed = $model instanceof User ? ['name', 'email', 'email_verified_at'] : $model->getFillable();
        if ($model instanceof Tank) {
            $allowed = [...$allowed, 'balance_ml', 'commodity_id'];
        }

        return Arr::only($values, $allowed);
    }

    private function record(Model $model, string $action, array $old, array $new): void
    {
        $stage = match (true) {
            $model instanceof Movement && $model->type === 'inbound' => 'Vessel discharge',
            $model instanceof Movement => 'Truck delivery',
            $model instanceof Shipment => 'Shipment',
            $model instanceof Tank => 'Tank storage',
            $model instanceof User => 'User accounts',
            default => 'Setup',
        };
        AuditEntry::create([
            'stage' => $stage, 'action' => $action, 'subject_type' => class_basename($model),
            'subject_id' => $model->getKey(), 'actor_id' => auth()->id(), 'actor_name' => auth()->user()?->name,
            'old_values' => $old, 'new_values' => $new, 'created_at' => now(),
        ]);
    }
}
