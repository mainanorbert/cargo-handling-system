<?php

namespace App\Filament\Resources\Vessels;

use App\Filament\Resources\Vessels\Pages\ManageVessels;
use App\Models\Vessel;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VesselResource extends Resource
{
    protected static ?string $model = Vessel::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Vessel';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->label('Vessel name')->required()->unique(ignoreRecord: true)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Vessel name')->searchable()])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageVessels::route('/')];
    }
}
