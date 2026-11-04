<?php

namespace App\Filament\Resources\Movements\Pages;

use App\Exceptions\CargoException;
use App\Filament\Resources\Movements\MovementResource;
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
            })->visible($type === 'inbound'),
            Select::make('truck_id')->label('Truck')->options(fn () => Truck::pluck('can_plate', 'id'))->searchable()->required()->visible($type === 'outbound'),
            Select::make('tank_id')->label('Shore tank')->options(fn () => Tank::all()->mapWithKeys(fn ($tank) => [$tank->id => $tank->name.' — '.number_format($tank->balance_ml / 1000, 3).' / '.number_format($tank->capacity_ml / 1000, 3).' L']))->searchable()->required()->live(),
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
            })->step(0.001)->live(onBlur: true)->helperText(function (Get $get) use ($type): HtmlString|string|null {
                if ($type === 'outbound') {
                    return 'No inbound loss is applied to deliveries.';
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
            Placeholder::make('preview')->label('Estimated delivery and tank balance')->visible($type === 'outbound')->content(function (Get $get): string {
                try {
                    $quantity = CargoService::millilitres((string) $get('quantity_litres'));
                } catch (CargoException) {
                    return 'Enter a valid quantity and select a tank to preview.';
                }
                $tank = $this->findTank($get('tank_id'));
                if (! $tank) {
                    return 'Select a tank to preview.';
                }
                if ($quantity > (int) $tank->balance_ml) {
                    return 'Delivery exceeds available tank stock of '.$this->formatLitres((int) $tank->balance_ml).' L.';
                }
                $projected = $tank->balance_ml - $quantity;

                return 'Projected balance after delivery: '.number_format($projected / 1000, 3).' L. Final checks validate the full event timeline when saved.';
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
                ->schema($this->movementFields())
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
