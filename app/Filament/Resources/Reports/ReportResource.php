<?php

namespace App\Filament\Resources\Reports;

use App\Filament\Resources\Reports\Pages\ListReports;
use App\Models\Shipment;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReportResource extends Resource
{
    protected static ?string $model = Shipment::class;

    protected static ?string $navigationLabel = 'Reports';

    protected static string|\UnitEnum|null $navigationGroup = 'Cargo workflow';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?int $navigationSort = 6;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['vessel', 'commodity'])
            ->withSum('inboundMovements as received_ml', 'quantity_ml');
    }

    public static function litres(int $millilitres): string
    {
        return intdiv($millilitres, 1000).'.'.str_pad((string) ($millilitres % 1000), 3, '0', STR_PAD_LEFT);
    }

    public static function percentage(int $received, int $ordered): string
    {
        if ($ordered <= 0) {
            return '0.00%';
        }

        return number_format($received / $ordered * 100, 2).'%';
    }

    public static function reportTypeFilter(string $currentType): Filter
    {
        return Filter::make('report_type')->schema([
            Select::make('type')->label('Report type')->options(['inbound' => 'Inbound', 'outbound' => 'Outbound'])
                ->default($currentType)->selectablePlaceholder(false)->live()
                ->afterStateUpdated(function (?string $state, $livewire): void {
                    if ($state === 'inbound') {
                        $livewire->redirect(ReportResource::getUrl());
                    } elseif ($state === 'outbound') {
                        $livewire->redirect(OutboundReportResource::getUrl());
                    }
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('vessel.name')->label('Ship')->searchable()->sortable(),
            TextColumn::make('commodity.code')->label('Commodity')->searchable()->sortable(),
            TextColumn::make('expected_ml')->label('Ordered (L)')->formatStateUsing(fn ($state): string => ReportResource::litres((int) $state)),
            TextColumn::make('received_ml')->label('Received (L)')->state(fn (Shipment $record): string => ReportResource::litres((int) $record->received_ml)),
            TextColumn::make('received_percentage')->label('Received (%)')
                ->state(fn (Shipment $record): string => self::percentage((int) $record->received_ml, (int) $record->expected_ml))
                ->badge()
                ->color(fn (Shipment $record): string => match (true) {
                    Shipment::percentageBasisPoints((int) $record->received_ml, (int) $record->expected_ml) > Shipment::COMPLETE_DISCHARGE_BASIS_POINTS => 'warning',
                    Shipment::percentageBasisPoints((int) $record->received_ml, (int) $record->expected_ml) >= Shipment::MINIMUM_ACCEPTABLE_DISCHARGE_BASIS_POINTS => 'success',
                    default => 'danger',
                })
                ->tooltip(fn (Shipment $record): string => match (true) {
                    Shipment::percentageBasisPoints((int) $record->received_ml, (int) $record->expected_ml) > Shipment::COMPLETE_DISCHARGE_BASIS_POINTS => 'Above allowed range',
                    Shipment::percentageBasisPoints((int) $record->received_ml, (int) $record->expected_ml) >= Shipment::MINIMUM_ACCEPTABLE_DISCHARGE_BASIS_POINTS => 'Allowed range',
                    default => 'Below allowed range',
                }),
        ])->filters([
            self::reportTypeFilter('inbound'),
            SelectFilter::make('vessel')->relationship('vessel', 'name')->label('Ship')->searchable()->preload(),
            SelectFilter::make('commodity')->relationship('commodity', 'code')->searchable()->preload(),
        ], layout: FiltersLayout::AboveContent)->deferFilters(false)->filtersFormColumns(3)
            ->recordActions([
                Action::make('report')->label('View report')->icon('heroicon-o-document-text')
                    ->url(fn (Shipment $record): string => route('inbound-reports.show', $record))->openUrlInNewTab(),
            ])->paginationPageOptions([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => ListReports::route('/')];
    }
}
