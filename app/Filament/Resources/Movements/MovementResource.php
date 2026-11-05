<?php

namespace App\Filament\Resources\Movements;

use App\Filament\Resources\Movements\Pages\RecordTruckDelivery;
use App\Filament\Resources\Movements\Pages\RecordVesselDischarge;
use App\Models\Movement;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MovementResource extends Resource
{
    protected static ?string $model = Movement::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Cargo workflow';

    protected static ?int $navigationSort = 3;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Movement')
                ->schema([
                    TextEntry::make('event_reference')->label('Event reference')->wrap()->columnSpanFull(),
                    TextEntry::make('type')->formatStateUsing(fn (string $state): string => ucfirst($state)),
                    TextEntry::make('status')->label('Processing status')->badge()->color(fn (string $state): string => $state === 'applied' ? 'success' : 'warning')->formatStateUsing(fn (string $state): string => $state === 'applied' ? 'Applied' : 'Needs review (late)'),
                    TextEntry::make('tank.name')->label('Tank'),
                    TextEntry::make('commodity.code')->label('Commodity'),
                    TextEntry::make('shipment.reference')->label('Inbound shipment')->placeholder('—')->wrap(),
                    TextEntry::make('truck.can_plate')->label('Cab plate')->placeholder('—')->wrap(),
                ])
                ->columns(2)
                ->compact(),
            Section::make('Quantities')
                ->schema([
                    TextEntry::make('source_ml')->label('Source quantity (L)')->formatStateUsing(fn ($state): string => number_format($state / 1000, 3)),
                    TextEntry::make('loss_ml')->label('Loss (L)')->formatStateUsing(fn ($state): string => number_format($state / 1000, 3)),
                    TextEntry::make('quantity_ml')->label('Received / delivered (L)')->formatStateUsing(fn ($state): string => number_format($state / 1000, 3)),
                    TextEntry::make('loss_basis_points')->label('Loss rate')->formatStateUsing(fn ($state): string => number_format($state / 100, 2).'%'),
                ])
                ->columns(2)
                ->compact(),
            Section::make('Audit')
                ->schema([
                    TextEntry::make('recordedBy.name')->label('Recorded by')->placeholder('System / integration')->tooltip(fn (Movement $record): ?string => $record->recordedBy?->email),
                    TextEntry::make('occurred_at')->label('Event time')->dateTime('d M Y H:i'),
                    TextEntry::make('received_at')->label('Recorded at')->dateTime('d M Y H:i'),
                    TextEntry::make('created_at')->label('Created at')->dateTime('d M Y H:i'),
                    TextEntry::make('updated_at')->label('Last updated at')->dateTime('d M Y H:i'),
                ])
                ->columns(2)
                ->compact(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('occurred_at')->orderByDesc('event_reference'))->columns([
            TextColumn::make('shipment_or_truck')->label('Truck')->state(fn (Movement $record): string => $record->truck?->can_plate ?? '—')->limit(20)->tooltip(fn (Movement $record): string => $record->truck?->can_plate ?? '—'),
            TextColumn::make('tank.name')->label('Tank')->visibleFrom('lg'),
            TextColumn::make('quantity_ml')->label('Net quantity (L)')->formatStateUsing(fn ($state): string => number_format($state / 1000, 3)),
            TextColumn::make('occurred_at')->label('Event time')->dateTime('d M H:i')->sortable(),
            TextColumn::make('status')->label('Status')->badge()->visibleFrom('xl')->color(fn (string $state): string => $state === 'applied' ? 'success' : 'warning')->formatStateUsing(fn (string $state): string => $state === 'applied' ? 'Applied' : 'Needs review (late)'),
        ])->recordActions([
            ViewAction::make()->modalHeading('Movement details')->modalWidth('3xl')->iconButton()->tooltip('View movement details'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'vessel-discharge' => RecordVesselDischarge::route('/vessel-discharge'),
            'truck-delivery' => RecordTruckDelivery::route('/truck-delivery'),
        ];
    }
}
