<?php

namespace App\Filament\Resources\Reports;

use App\Filament\Resources\Reports\Pages\ListReports;
use App\Models\Movement;
use Filament\Forms\Components\DateTimePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReportResource extends Resource
{
    protected static ?string $model = Movement::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Reports';

    protected static string|\UnitEnum|null $navigationGroup = 'Cargo workflow';

    protected static ?int $navigationSort = 6;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['shipment.vessel', 'tank', 'truck', 'commodity']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('occurred_at')->orderByDesc('event_reference'))
            ->columns([
                TextColumn::make('occurred_at')->label('Event time (UTC)')->dateTime('d M Y H:i:s')->sortable(),
                TextColumn::make('type')->label('Stage')->formatStateUsing(fn (string $state): string => ucfirst($state))->badge(),
                TextColumn::make('event_reference')->label('Event reference')->searchable()->wrap(),
                TextColumn::make('shipment.reference')->label('Shipment')->searchable()->placeholder('—'),
                TextColumn::make('shipment.vessel.name')->label('Vessel')->searchable()->placeholder('—'),
                TextColumn::make('truck.can_plate')->label('Truck')->searchable()->placeholder('—'),
                TextColumn::make('tank.name')->label('Tank')->searchable(),
                TextColumn::make('commodity.code')->label('Commodity')->searchable(),
                TextColumn::make('source_ml')->label('Source (L)')->formatStateUsing(fn ($state): string => self::litres((int) $state)),
                TextColumn::make('loss_ml')->label('Loss (L)')->formatStateUsing(fn ($state): string => self::litres((int) $state)),
                TextColumn::make('quantity_ml')->label('Received / delivered (L)')->formatStateUsing(fn ($state): string => self::litres((int) $state)),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('received_at')->label('Recorded at (UTC)')->dateTime('d M Y H:i:s'),
            ])
            ->filters([
                SelectFilter::make('type')->label('Stage')->options(['inbound' => 'Inbound', 'outbound' => 'Outbound']),
                Filter::make('event_time')
                    ->schema([
                        DateTimePicker::make('from')->label('From (UTC)')->seconds(),
                        DateTimePicker::make('until')->label('Until (UTC)')->seconds(),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $from): Builder => $query->where('occurred_at', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $query, string $until): Builder => $query->where('occurred_at', '<=', $until))),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(2)
            ->paginationPageOptions([10, 25, 50]);
    }

    public static function litres(int $millilitres): string
    {
        return intdiv($millilitres, 1000).'.'.str_pad((string) ($millilitres % 1000), 3, '0', STR_PAD_LEFT);
    }

    public static function getPages(): array
    {
        return ['index' => ListReports::route('/')];
    }
}
