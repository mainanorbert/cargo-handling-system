<?php

namespace App\Filament\Resources\AuditEntries;

use App\Filament\Resources\AuditEntries\Pages\ListAuditEntries;
use App\Models\AuditEntry;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class AuditEntryResource extends Resource
{
    protected static ?string $model = AuditEntry::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Audits';

    protected static string|\UnitEnum|null $navigationGroup = 'Audits';

    protected static ?int $navigationSort = 6;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('old_values')->label('Previous values')->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '—')->columnSpanFull(),
            TextEntry::make('new_values')->label('New values')->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '—')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->poll('10s')->defaultSort('id', 'desc')
            ->groups([Group::make('stage')->label('Clearance stage')])->defaultGroup('stage')
            ->columns([
                TextColumn::make('created_at')->label('Recorded at')->dateTime('d M Y H:i:s')->sortable(),
                TextColumn::make('stage')->label('Stage')->searchable(),
                TextColumn::make('action')->badge(),
                TextColumn::make('subject_type')->label('Record type')->searchable(),
                TextColumn::make('subject_id')->label('Record ID'),
                TextColumn::make('actor_name')->label('Actor')->placeholder('System / integration')->searchable(),
            ])
            ->filters([SelectFilter::make('stage')->options([
                'Shipment' => 'Shipment', 'Vessel discharge' => 'Vessel discharge', 'Tank storage' => 'Tank storage', 'Truck delivery' => 'Truck delivery', 'Setup' => 'Setup',
            ])])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditEntries::route('/')];
    }
}
