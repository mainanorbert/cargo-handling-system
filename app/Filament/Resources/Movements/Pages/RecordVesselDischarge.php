<?php

namespace App\Filament\Resources\Movements\Pages;

use App\Models\Movement;
use App\Models\Shipment;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class RecordVesselDischarge extends RecordMovement
{
    protected static ?string $title = 'Inbound Cargo';

    protected function getMovementType(): string
    {
        return 'inbound';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Shipment::query()
                    ->with([
                        'commodity',
                        'vessel',
                        'inboundMovements' => fn (HasMany $query): HasMany => $query
                            ->with(['tank', 'recordedBy'])
                            ->orderByDesc('occurred_at')
                            ->orderByDesc('event_reference'),
                    ])
                    ->withSum('inboundMovements as discharged_ml', 'source_ml')
            )
            ->modelLabel('inbound shipment')
            ->pluralModelLabel('inbound shipments')
            ->defaultSort('created_at', 'desc')
            ->extraAttributes(['class' => 'vessel-discharge-table'])
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('reference')->label('Shipment')->formatStateUsing(fn (string $state): string => Str::limit($state, 7))->tooltip(fn (string $state): string => $state)->size(TextSize::ExtraSmall)->width('10%')->searchable()->sortable(),
                TextColumn::make('expected_ml')->label('Shipped Qty')->headerTooltip('Shipped quantity before discharge (L)')->formatStateUsing(fn ($state): string => number_format($state / 1000, 3))->size(TextSize::ExtraSmall)->width('17%')->wrapHeader(),
                TextColumn::make('discharged_ml')->label('Offloaded')->headerTooltip('Offloaded so far (L)')->state(fn (Shipment $record): int => $record->dischargedMillilitres())->formatStateUsing(fn ($state): string => number_format($state / 1000, 3))->size(TextSize::ExtraSmall)->width('16%')->wrapHeader(),
                TextColumn::make('unaccounted_percentage')->label('Unaccounted (%)')->headerTooltip('Percentage of the shipment not yet offloaded')->state(fn (Shipment $record): string => $record->formattedUnaccountedPercentage())->size(TextSize::ExtraSmall)->width('16%')->wrapHeader(),
                TextColumn::make('discharge_percentage')->label('Offloaded (%)')->headerTooltip('Offloaded percentage')->state(fn (Shipment $record): string => $record->formattedDischargePercentage())->badge()->size(TextSize::ExtraSmall)->width('13%')->color(fn (Shipment $record): string => $this->offloadedStatusColor($record))->tooltip(fn (Shipment $record): string => $this->offloadedStatusTooltip($record)),
                TextColumn::make('allocations')->label('Tanks')->headerTooltip('Tank allocations')->state(fn (Shipment $record): string => $this->formatAllocatedTanks($record))->size(TextSize::ExtraSmall)->width('20%')->wrap()->placeholder('No discharge recorded'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->modalHeading(fn (Shipment $record): string => $record->reference.' discharge details')
                    ->modalWidth('5xl')
                    ->schema([
                        Section::make('Shipment progress')
                            ->schema([
                                TextEntry::make('reference')->label('Shipment reference'),
                                TextEntry::make('expected_ml')->label('Net quantity before discharge (L)')->formatStateUsing(fn ($state): string => number_format($state / 1000, 3)),
                                TextEntry::make('discharged_ml')->label('Offloaded so far (L)')->state(fn (Shipment $record): int => $record->dischargedMillilitres())->formatStateUsing(fn ($state): string => number_format($state / 1000, 3)),
                                TextEntry::make('remaining_ml')->label('Remaining (L)')->state(fn (Shipment $record): int => $record->remainingMillilitres())->formatStateUsing(fn ($state): string => number_format($state / 1000, 3)),
                                TextEntry::make('discharge_percentage')->label('Offloaded')->state(fn (Shipment $record): string => $record->formattedDischargePercentage())->badge()->color(fn (Shipment $record): string => $this->offloadedStatusColor($record))->tooltip(fn (Shipment $record): string => $this->offloadedStatusTooltip($record)),
                            ])
                            ->columns(4),
                        Section::make('Tank discharge history')
                            ->schema([
                                RepeatableEntry::make('inboundMovements')
                                    ->hiddenLabel()
                                    ->schema([
                                        TextEntry::make('event_reference')->label('Event reference')->wrap(),
                                        TextEntry::make('tank.name')->label('Tank'),
                                        TextEntry::make('source_ml')->label('Discharged quantity (L)')->formatStateUsing(fn ($state): string => number_format($state / 1000, 3)),
                                        TextEntry::make('occurred_at')->label('Event time')->dateTime('d M Y H:i'),
                                        TextEntry::make('recordedBy.name')->label('Recorded by')->placeholder('System / integration'),
                                    ])
                                    ->columns(5),
                            ]),
                    ])
                    ->iconButton()
                    ->tooltip('View tank discharge history'),
            ])
            ->paginationPageOptions([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }

    private function formatAllocatedTanks(Shipment $shipment): string
    {
        return $shipment->inboundMovements
            ->map(fn (Movement $movement): string => $movement->tank->name)
            ->unique()
            ->sort()
            ->values()
            ->implode(', ');
    }

    private function offloadedStatusTooltip(Shipment $shipment): string
    {
        return match (true) {
            $shipment->dischargePercentageBasisPoints() > Shipment::COMPLETE_DISCHARGE_BASIS_POINTS => 'Above allowed range',
            $shipment->meetsDischargeExpectation() => 'Allowed range',
            default => 'Below allowed range',
        };
    }

    private function offloadedStatusColor(Shipment $shipment): string
    {
        return match (true) {
            $shipment->dischargePercentageBasisPoints() > Shipment::COMPLETE_DISCHARGE_BASIS_POINTS => 'warning',
            $shipment->meetsDischargeExpectation() => 'success',
            default => 'danger',
        };
    }
}
