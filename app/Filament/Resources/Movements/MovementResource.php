<?php

namespace App\Filament\Resources\Movements;

use App\Filament\Resources\Movements\Pages\ManageMovements;
use App\Filament\Resources\Movements\Pages\RecordTruckDelivery;
use App\Filament\Resources\Movements\Pages\RecordVesselDischarge;
use App\Models\Movement;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MovementResource extends Resource
{
    protected static ?string $model = Movement::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Cargo workflow';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Cargo movements';

    public static function table(Table $table): Table
    {
        return $table->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('occurred_at')->orderByDesc('event_reference'))->columns([
            TextColumn::make('event_reference')->label('Event reference')->searchable(), TextColumn::make('type')->badge(), TextColumn::make('status')->label('Processing status')->badge()->color(fn (string $state): string => $state === 'applied' ? 'success' : 'warning')->formatStateUsing(fn (string $state): string => $state === 'applied' ? 'Applied' : 'Needs review (late)'),
            TextColumn::make('shipment.reference')->label('Shipment')->placeholder('—')->searchable(), TextColumn::make('truck.registration')->label('Truck')->placeholder('—'), TextColumn::make('tank.name')->label('Tank'), TextColumn::make('commodity.code')->label('Commodity'),
            TextColumn::make('source_ml')->label('Source (L)')->formatStateUsing(fn ($state) => number_format($state / 1000, 3)), TextColumn::make('loss_ml')->label('Loss (L)')->formatStateUsing(fn ($state) => number_format($state / 1000, 3)), TextColumn::make('quantity_ml')->label('Received / delivered (L)')->formatStateUsing(fn ($state) => number_format($state / 1000, 3)), TextColumn::make('occurred_at')->label('Event time')->dateTime()->sortable(), TextColumn::make('received_at')->label('Recorded at')->dateTime(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMovements::route('/'),
            'vessel-discharge' => RecordVesselDischarge::route('/vessel-discharge'),
            'truck-delivery' => RecordTruckDelivery::route('/truck-delivery'),
        ];
    }

    public static function getNavigationItemActiveRoutePattern(): string
    {
        return static::getRouteBaseName().'.index';
    }
}
