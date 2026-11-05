<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Resources\Reports\MovementReportResource;
use App\Filament\Resources\Reports\ReportResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListReports extends ListRecords
{
    protected static string $resource = ReportResource::class;

    public function getHeading(): string
    {
        return 'Reports';
    }

    public function getSubheading(): string
    {
        return 'Filter shipments to create a report.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('movements')->label('View movements')->icon('heroicon-o-list-bullet')
                ->url(MovementReportResource::getUrl()),
            Action::make('report')->label('Create report')->icon('heroicon-o-printer')
                ->action(function (): void {
                    abort_unless(ReportResource::canViewAny(), 403);
                    session(['inbound_report_shipment_ids' => $this->getFilteredSortedTableQuery()->pluck('shipments.id')->all()]);
                    session(['inbound_report_criteria' => ['scope' => 'filtered', 'filters' => $this->tableFilters, 'search' => $this->tableSearch]]);
                    $this->redirect(route('inbound-reports.filtered'));
                }),
        ];
    }
}
