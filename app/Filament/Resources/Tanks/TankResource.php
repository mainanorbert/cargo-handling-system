<?php

namespace App\Filament\Resources\Tanks;

use App\Exceptions\CargoException;
use App\Filament\Resources\Tanks\Pages\ManageTanks;
use App\Models\Movement;
use App\Models\Tank;
use App\Services\CargoService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TankResource extends Resource
{
    protected static ?string $model = Tank::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 4;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $modelLabel = 'Tank';

    protected static ?string $navigationLabel = 'Shore Storage Tanks';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->label('Tank name')->required()->unique(ignoreRecord: true), TextInput::make('capacity_ml')->label('Capacity (L)')->disabled(fn ($record) => $record !== null)->helperText('Capacity is set when the tank is created.')->required()->numeric()->minValue(0.001)->step(0.001)->formatStateUsing(fn ($state) => $state === null ? null : number_format($state / 1000, 3, '.', ''))->dehydrateStateUsing(fn ($state) => CargoService::millilitres((string) $state))->rule(fn ($record) => function (string $attribute, $value, $fail) use ($record) {
            try {
                if ($record && CargoService::millilitres((string) $value) < $record->balance_ml) {
                    $fail('Capacity cannot be below the current balance.');
                }
            } catch (CargoException $e) {
                $fail($e->getMessage());
            }
        })]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable(), TextColumn::make('commodity.code')->label('Current commodity')->placeholder('Empty'), TextColumn::make('capacity_ml')->label('Capacity (L)')->formatStateUsing(fn ($state) => number_format($state / 1000, 3)), TextColumn::make('balance_ml')->label('Balance (L)')->formatStateUsing(fn ($state) => number_format($state / 1000, 3)), TextColumn::make('utilisation')->label('Utilisation')->state(fn ($record) => number_format($record->balance_ml * 100 / $record->capacity_ml, 1).'%')])->recordActions([EditAction::make(), DeleteAction::make()->visible(fn ($record) => ! Movement::where('tank_id', $record->id)->exists())]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTanks::route('/')];
    }
}
