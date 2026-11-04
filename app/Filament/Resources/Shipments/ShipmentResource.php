<?php

namespace App\Filament\Resources\Shipments;

use App\Filament\Resources\Shipments\Pages\ManageShipments;
use App\Models\Movement;
use App\Models\Shipment;
use App\Services\CargoService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Cargo workflow';

    protected static ?int $navigationSort = 1;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box-arrow-down';

    protected static ?string $modelLabel = 'Inbound shipment';

    protected static ?string $navigationLabel = 'Shipments';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('reference')->label('Shipment reference')->default(fn (): string => 'SHIP-'.Str::uuid())->required()->unique(ignoreRecord: true), TextInput::make('origin')->label('Point of origin')->required(), Select::make('vessel_id')->label('Vessel')->relationship('vessel', 'name')->searchable()->preload()->required(), Select::make('commodity_id')->label('Commodity')->relationship('commodity', 'code')->preload()->required(), TextInput::make('expected_ml')->label('Quantity (L)')->required()->numeric()->minValue(0.001)->step(0.001)->formatStateUsing(fn ($state) => $state === null ? null : number_format($state / 1000, 3, '.', ''))->dehydrateStateUsing(fn ($state) => CargoService::millilitres((string) $state))]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('reference')->label('Shipment reference')->searchable(), TextColumn::make('origin')->label('Point of origin')->searchable(), TextColumn::make('vessel.name')->label('Vessel'), TextColumn::make('commodity.code')->label('Commodity'), TextColumn::make('expected_ml')->label('Quantity (L)')->formatStateUsing(fn ($state) => number_format($state / 1000, 3))])->recordActions([EditAction::make()->visible(fn ($record) => ! Movement::where('shipment_id', $record->id)->exists()), DeleteAction::make()->visible(fn ($record) => ! Movement::where('shipment_id', $record->id)->exists())]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageShipments::route('/')];
    }
}
