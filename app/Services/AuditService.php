<?php

namespace App\Services;

use App\Models\AuditEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AuditService
{
    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $criteria
     */
    public function reportGenerated(string $report, Collection $records, array $criteria = [], string $format = 'Print-ready report'): void
    {
        AuditEntry::create([
            'stage' => 'Reports',
            'action' => 'generated',
            'subject_type' => $report,
            'subject_id' => $records->count() === 1 ? (int) $records->first()->getKey() : 0,
            'actor_id' => auth()->id(),
            'actor_name' => auth()->user()?->name,
            'old_values' => [],
            'new_values' => [
                'format' => $format,
                'record_count' => $records->count(),
                'record_ids' => $records->map(fn ($record) => $record->getKey())->values()->all(),
                'criteria' => $criteria,
            ],
            'created_at' => now(),
        ]);
    }
}
