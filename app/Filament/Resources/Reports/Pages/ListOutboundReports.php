<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Resources\Reports\MovementReportResource;
use App\Filament\Resources\Reports\OutboundReportResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListOutboundReports extends ListRecords
{
    protected static string $resource = OutboundReportResource::class;

    public function getHeading(): string
    {
        return 'Reports';
    }

    public function getSubheading(): string
    {
        return 'Filter deliveries to create a report.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('movements')->label('View movements')->icon('heroicon-o-list-bullet')->url(MovementReportResource::getUrl()),
            Action::make('report')->label('Create report')->icon('heroicon-o-printer')
                ->action(function (): void {
                    abort_unless(OutboundReportResource::canViewAny(), 403);
                    session(['outbound_report_movement_ids' => $this->getFilteredSortedTableQuery()->pluck('movements.id')->all()]);
                    session(['outbound_report_criteria' => ['scope' => 'filtered', 'filters' => $this->tableFilters, 'search' => $this->tableSearch]]);
                    $this->redirect(route('outbound-reports.filtered'));
                }),
        ];
    }
}
