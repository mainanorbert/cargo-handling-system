<?php

namespace App\Filament\Resources\AuditEntries;

use App\Filament\Resources\AuditEntries\Pages\ListAuditEntries;
use App\Models\AuditEntry;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditEntryResource extends Resource
{
    protected static ?string $model = AuditEntry::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Audits';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports & Audits';

    protected static ?int $navigationSort = 6;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('actor');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Event details')->columns(2)->schema([
                TextEntry::make('created_at')->label('Recorded at (UTC)')->dateTime('d M Y H:i:s'),
                TextEntry::make('actor_name')->label('Performed by')->placeholder('System / integration'),
                TextEntry::make('actor.email')->label('Actor email')->placeholder('System / integration'),
                TextEntry::make('actor_id')->label('User ID')->placeholder('—'),
                TextEntry::make('stage')->label('Area'),
                TextEntry::make('action')->label('Action')->badge(),
                TextEntry::make('subject_type')->label('Record / report type'),
                TextEntry::make('subject_id')->label('Record ID')->formatStateUsing(fn ($state): string => (int) $state === 0 ? 'Multiple / none' : (string) $state),
            ]),
            Section::make('Recorded details')->schema([
                TextEntry::make('old_values')->label('Previous values')->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '—')->columnSpanFull(),
                TextEntry::make('new_values')->label('New values / report details')->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->poll('10s')->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Recorded at (UTC)')->dateTime('d M Y H:i:s')->sortable(),
                TextColumn::make('actor_name')->label('Performed by')->placeholder('System / integration')->searchable(),
                TextColumn::make('actor.email')->label('Actor email')->placeholder('System / integration')->searchable(),
                TextColumn::make('stage')->label('Area')->searchable(),
                TextColumn::make('action')->label('Action')->badge()->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('subject_type')->label('Record / report type')->searchable(),
                TextColumn::make('subject_id')->label('Record ID')->formatStateUsing(fn ($state): string => (int) $state === 0 ? 'Multiple / none' : (string) $state),
            ])
            ->filters([
                SelectFilter::make('stage')->label('Area')->options([
                    'Shipment' => 'Shipment', 'Vessel discharge' => 'Vessel discharge', 'Tank storage' => 'Tank storage',
                    'Truck delivery' => 'Truck delivery', 'Setup' => 'Setup', 'User accounts' => 'User accounts', 'Reports' => 'Reports',
                ]),
                SelectFilter::make('action')->options(['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted', 'generated' => 'Generated']),
                SelectFilter::make('actor')
                    ->label('Performed by')
                    ->relationship('actor', 'email', hasEmptyOption: true)
                    ->getOptionLabelFromRecordUsing(fn (User $record): string => "{$record->email} ({$record->name})")
                    ->emptyRelationshipOptionLabel('System / integration')
                    ->searchable()
                    ->preload(),
                Filter::make('recorded_at')
                    ->schema([
                        DateTimePicker::make('from')
                            ->label('Start')
                            ->native(false)
                            ->displayFormat('d/m/y H:i:s')
                            ->placeholder('DD/MM/YY HH:MM:SS')
                            ->extraTriggerAttributes(['style' => 'font-size: 0.75rem; line-height: 1rem;'])
                            ->seconds(),
                        DateTimePicker::make('until')
                            ->label('End')
                            ->native(false)
                            ->displayFormat('d/m/y H:i:s')
                            ->placeholder('DD/MM/YY HH:MM:SS')
                            ->extraTriggerAttributes(['style' => 'font-size: 0.75rem; line-height: 1rem;'])
                            ->seconds(),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $from): Builder => $query->where('created_at', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $query, string $until): Builder => $query->where('created_at', '<=', $until))),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(4)
            ->recordActions([ViewAction::make()->label('Details')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditEntries::route('/')];
    }
}
