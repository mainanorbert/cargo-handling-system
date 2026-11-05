<?php

namespace App\Filament\Resources\AuditEntries\Pages;

use App\Filament\Resources\AuditEntries\AuditEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditEntries extends ListRecords
{
    protected static string $resource = AuditEntryResource::class;
}
