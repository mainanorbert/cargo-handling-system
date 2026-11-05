<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Resources\Reports\ReportResource;
use App\Models\Movement;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListReports extends ListRecords
{
    protected static string $resource = ReportResource::class;

    public function getHeading(): string
    {
        return 'Cargo reports';
    }

    public function getSubheading(): string
    {
        return 'Tanks shown are those recorded on each movement; current stock may have changed.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label('Download CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function (): StreamedResponse {
                    abort_unless(ReportResource::canViewAny(), 403);
                    $query = $this->getFilteredSortedTableQuery();

                    return response()->streamDownload(function () use ($query): void {
                        $file = fopen('php://output', 'w');
                        fputcsv($file, ['Event time (UTC)', 'Stage', 'Event reference', 'Shipment', 'Vessel', 'Truck', 'Tank', 'Commodity', 'Source (L)', 'Loss (L)', 'Received / delivered (L)', 'Status', 'Recorded at (UTC)'], escape: '');
                        foreach ($query->lazy() as $movement) {
                            /** @var Movement $movement */
                            $values = [
                                $movement->occurred_at?->format('Y-m-d H:i:s'), $movement->type, $movement->event_reference,
                                $movement->shipment?->reference, $movement->shipment?->vessel?->name, $movement->truck?->can_plate,
                                $movement->tank?->name, $movement->commodity?->code, ReportResource::litres((int) $movement->source_ml),
                                ReportResource::litres((int) $movement->loss_ml), ReportResource::litres((int) $movement->quantity_ml),
                                $movement->status, $movement->received_at?->format('Y-m-d H:i:s'),
                            ];
                            fputcsv($file, array_map($this->safeCsvValue(...), $values), escape: '');
                        }
                        fclose($file);
                    }, 'cargo-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
        ];
    }

    private function safeCsvValue(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s\x00-\x1f]*[=+@\-]/u', $value) ? "'".$value : $value;
    }
}
