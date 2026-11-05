<?php

namespace App\Filament\Resources\Reports;

use App\Filament\Resources\Reports\Pages\ListOutboundReports;
use App\Models\Movement;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OutboundReportResource extends Resource
{
    protected static ?string $model = Movement::class;

    protected static ?string $slug = 'outbound-reports';

    protected static bool $shouldRegisterNavigation = false;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', 'outbound')->with(['truck', 'tank', 'commodity']);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('occurred_at')->orderByDesc('event_reference'))
            ->columns([
                TextColumn::make('occurred_at')->label('Event time (UTC)')->dateTime('d M Y H:i:s')->sortable(),
                TextColumn::make('truck.can_plate')->label('Truck')->searchable(),
                TextColumn::make('commodity.code')->label('Commodity')->searchable(),
                TextColumn::make('tank.name')->label('Tank')->searchable(),
                TextColumn::make('quantity_ml')->label('Delivered (L)')->formatStateUsing(fn ($state): string => ReportResource::litres((int) $state)),
                TextColumn::make('status')->label('Status')->badge(),
            ])->filters([
                ReportResource::reportTypeFilter('outbound'),
                SelectFilter::make('commodity')->relationship('commodity', 'code')->searchable()->preload(),
                SelectFilter::make('truck')->relationship('truck', 'can_plate')->searchable()->preload(),
                Filter::make('event_time')->schema([
                    DateTimePicker::make('from')->label('From (UTC)')->seconds(),
                    DateTimePicker::make('until')->label('Until (UTC)')->seconds(),
                ])->columns(2)->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, string $from): Builder => $query->where('occurred_at', '>=', $from))
                    ->when($data['until'] ?? null, fn (Builder $query, string $until): Builder => $query->where('occurred_at', '<=', $until))),
            ], layout: FiltersLayout::AboveContent)->deferFilters(false)->filtersFormColumns(4)
            ->recordActions([
                Action::make('report')->label('View report')->icon('heroicon-o-document-text')
                    ->url(fn (Movement $record): string => route('outbound-reports.show', $record))->openUrlInNewTab(),
            ])->paginationPageOptions([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOutboundReports::route('/')];
    }
}
