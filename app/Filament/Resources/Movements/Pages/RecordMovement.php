<?php

namespace App\Filament\Resources\Movements\Pages;

use App\Exceptions\CargoException;
use App\Filament\Resources\Movements\MovementResource;
use App\Models\Commodity;
use App\Models\Shipment;
use App\Models\Tank;
use App\Models\Truck;
use App\Services\CargoService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

abstract class RecordMovement extends ManageRecords
{
    protected static string $resource = MovementResource::class;

    #[Locked]
    public string $eventReference = '';

    abstract protected function getMovementType(): string;

    protected function movementFields(): array
    {
        $type = $this->getMovementType();

        return [
            Placeholder::make('event_reference')->label('Event reference')->content(fn (): string => $this->eventReference)->helperText('Generated automatically for this movement.'),
            Select::make('shipment_id')->label('Inbound shipment')->options(fn () => Shipment::pluck('reference', 'id'))->searchable()->required()->live()->afterStateUpdated(function (Set $set, int|string|null $state): void {
                $shipment = $this->findShipmentWithDischargeTotal($state);
                $set('quantity_litres', $shipment ? $this->formatLitresForInput($shipment->remainingMillilitres()) : null);
                $set('tank_id', null);
            })->visible($type === 'inbound'),
            Placeholder::make('shipment_commodity')->label('Shipment commodity')->content(function (Get $get): string {
                $shipment = $this->findShipmentWithDischargeTotal($get('shipment_id'));

                return $shipment?->commodity
                    ? $shipment->commodity->code.' — '.$shipment->commodity->description
                    : 'Select an inbound shipment to view its commodity.';
            })->visible($type === 'inbound'),
            Select::make('truck_id')->label('Cab plate')->options(fn () => Truck::pluck('can_plate', 'id'))->searchable()->required()->live()->visible($type === 'outbound'),
            Placeholder::make('trailer_plate')->label('Trailer plate')->content(fn (Get $get): string => Truck::find($get('truck_id'))?->trail_plate ?: 'No trailer plate recorded')->visible($type === 'outbound'),
            Select::make('commodity_id')->label('Commodity being transported')->options(fn () => Commodity::pluck('code', 'id'))->searchable()->required()->live()->afterStateUpdated(fn (Set $set) => $set('tank_id', null))->visible($type === 'outbound'),
            Placeholder::make('tank_compatibility')->label('Tank compatibility')->content(function (Get $get) use ($type): string {
                if ($type !== 'inbound') {
                    return '';
                }

                $shipment = $this->findShipmentWithDischargeTotal($get('shipment_id'));

                if (! $shipment?->commodity) {
                    return 'Select an inbound shipment to compare its commodity with current tank contents.';
                }

                return Tank::query()
                    ->with('commodity')
                    ->orderBy('name')
                    ->get()
                    ->map(function (Tank $tank) use ($shipment): string {
                        $commodity = $tank->commodity?->code ?? 'Empty';
                        $availability = $tank->commodity_id === null || $tank->commodity_id === $shipment->commodity_id
                            ? 'available'
                            : 'unavailable — contains a different commodity';

                        return $tank->name.' — '.$commodity.'; '.$this->formatLitres((int) $tank->balance_ml).' / '
                            .$this->formatLitres((int) $tank->capacity_ml).' L; '.$availability;
                    })
                    ->join('; ')
                    ?: 'No shore tanks have been configured.';
            })->visible($type === 'inbound'),
            Select::make('tank_id')->label('Shore tank')->options(function (Get $get) use ($type) {
                $shipment = $type === 'inbound' ? $this->findShipmentWithDischargeTotal($get('shipment_id')) : null;

                return Tank::query()
                    ->with('commodity')
                    ->when($type === 'inbound' && $shipment, fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNull('commodity_id')->orWhere('commodity_id', $shipment->commodity_id)))
                    ->when($type === 'outbound', fn (Builder $query) => $query->whereNotNull('commodity_id')->where('commodity_id', $get('commodity_id')))
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (Tank $tank) => [$tank->id => $tank->name.' — '.($tank->commodity?->code ?? 'Empty').'; '
                        .$this->formatLitres((int) $tank->balance_ml).' / '.$this->formatLitres((int) $tank->capacity_ml).' L']);
            })->helperText(function (Get $get) use ($type): ?string {
                if ($type !== 'inbound') {
                    return null;
                }

                $shipment = $this->findShipmentWithDischargeTotal($get('shipment_id'));

                return $shipment?->commodity
                    ? 'Only empty tanks or tanks already holding '.$shipment->commodity->code.' can receive this shipment.'
                    : 'Choose a shipment to show compatible tanks.';
            })->searchable()->required()->live(),
            TextInput::make('quantity_litres')->label($type === 'inbound' ? 'Discharged quantity (L)' : 'Delivery quantity (L)')->required()->numeric()->minValue(0.001)->maxValue(function (Get $get) use ($type): float|string|null {
                if ($type === 'inbound') {
                    $shipment = $this->findShipmentWithDischargeTotal($get('shipment_id'));

                    return $shipment ? $this->formatLitresForInput($shipment->remainingMillilitres()) : null;
                }

                if ($type === 'outbound') {
                    $tank = $this->findTank($get('tank_id'));

                    return $tank ? $this->formatLitresForInput((int) $tank->balance_ml) : null;
                }

                return null;
            })->validationMessages([
                'max' => $type === 'inbound'
                    ? 'Quantity cannot exceed the amount remaining in the selected shipment.'
                    : 'Quantity cannot exceed the available stock in the selected tank.',
            ])->step(0.001)->live(onBlur: true)->helperText(function (Get $get) use ($type): HtmlString|string|null {
                if ($type === 'outbound') {
                    return null;
                }
                $shipment = $this->findShipmentWithDischargeTotal($get('shipment_id'));
                if (! $shipment) {
                    return null;
                }

                return new HtmlString(
                    '<span class="text-gray-500 dark:text-gray-400" style="font-size: 0.6875rem; line-height: 1rem;">Original quantity: '
                    .$this->formatLitres((int) $shipment->expected_ml).' L; offloaded: '
                    .$this->formatLitres($shipment->dischargedMillilitres()).' L ('.$shipment->formattedDischargePercentage().')</span>'
                );
            }),
            DateTimePicker::make('occurred_at')->label('Event time')->required()->default(now()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', $this->getMovementType())->with(['shipment', 'truck', 'tank', 'commodity', 'recordedBy']))
            ->paginationPageOptions([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('record')
                ->label($this->getTitle())
                ->modalHeading($this->getTitle())
                ->modalSubmitActionLabel('Save movement')
                ->visible(fn (): bool => MovementResource::canCreate())
                ->modalWidth('3xl')
                ->schema([Grid::make(['default' => 1, 'sm' => 2])->schema($this->movementFields())])
                ->mountUsing(function (Schema $schema): void {
                    $this->eventReference = (string) Str::uuid();
                    $schema->fill();
                })
                ->action(function (array $data): void {
                    abort_unless(MovementResource::canCreate(), 403);
                    try {
                        app(CargoService::class)->record([...$data, 'type' => $this->getMovementType(), 'event_reference' => $this->eventReference]);
                    } catch (CargoException $exception) {
                        throw ValidationException::withMessages([
                            $this->getMountedActionSchema()->getStatePath().'.quantity_litres' => $exception->getMessage(),
                        ]);
                    }
                    Notification::make()->title('Movement applied')->success()->send();
                    $this->resetTable();
                }),
        ];
    }

    private function findShipmentWithDischargeTotal(int|string|null $shipmentId): ?Shipment
    {
        if (blank($shipmentId)) {
            return null;
        }

        return Shipment::query()
            ->with('commodity')
            ->withSum('inboundMovements as discharged_ml', 'source_ml')
            ->find($shipmentId);
    }

    private function findTank(int|string|null $tankId): ?Tank
    {
        if (blank($tankId)) {
            return null;
        }

        return Tank::find($tankId);
    }

    private function formatLitres(int $millilitres): string
    {
        return number_format($millilitres / 1000, 3, '.', ',');
    }

    private function formatLitresForInput(int $millilitres): string
    {
        return number_format($millilitres / 1000, 3, '.', '');
    }
}
