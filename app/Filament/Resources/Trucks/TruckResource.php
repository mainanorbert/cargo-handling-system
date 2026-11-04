<?php

namespace App\Filament\Resources\Trucks;

use App\Filament\Resources\Trucks\Pages\ManageTrucks;
use App\Models\Truck;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TruckResource extends Resource
{
    protected static ?string $model = Truck::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $modelLabel = 'Truck';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('registration')->label('Truck registration')->required()->unique(ignoreRecord: true)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('registration')->label('Truck registration')->searchable()])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTrucks::route('/')];
    }
}
