<?php

namespace App\Filament\Resources\Commodities;

use App\Filament\Resources\Commodities\Pages\ManageCommodities;
use App\Models\Commodity;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommodityResource extends Resource
{
    protected static ?string $model = Commodity::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Commodity';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('code')->label('Commodity code')->required()->maxLength(20)->unique(ignoreRecord: true), TextInput::make('description')->required(), Toggle::make('requires_temperature_control')->label('Temperature control required')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('code')->searchable(), TextColumn::make('description')->searchable(), IconColumn::make('requires_temperature_control')->boolean()->label('Temperature control required')])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCommodities::route('/')];
    }
}
