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
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
            Select::make('shipment_id')->label('Inbound shipment')->options(fn () => Shipment::pluck('reference', 'id'))->searchable()->required()->live()->visible($type === 'inbound'),
            Select::make('truck_id')->label('Truck')->options(fn () => Truck::pluck('registration', 'id'))->searchable()->required()->visible($type === 'outbound'),
            Select::make('tank_id')->label('Shore tank')->options(fn () => Tank::all()->mapWithKeys(fn ($tank) => [$tank->id => $tank->name.' — '.number_format($tank->balance_ml / 1000, 3).' / '.number_format($tank->capacity_ml / 1000, 3).' L']))->searchable()->required()->live(),
            TextInput::make('quantity_litres')->label($type === 'inbound' ? 'Discharged quantity (L)' : 'Delivery quantity (L)')->required()->numeric()->minValue(0.001)->step(0.001)->live(onBlur: true)->helperText($type === 'inbound' ? 'A 0.5% loss is applied before the tank is credited.' : 'No inbound loss is applied to deliveries.'),
            Placeholder::make('inbound_quantity')->label('Inbound shipment quantity')->visible($type === 'inbound')->content(function (Get $get): string {
                $shipment = Shipment::find($get('shipment_id'));
                if (! $shipment) {
                    return 'Select an inbound shipment to view its quantity.';
                }
                try {
                    $discharged = CargoService::millilitres((string) $get('quantity_litres'));
                } catch (CargoException) {
                    return 'Shipment quantity: '.number_format($shipment->expected_ml / 1000, 3).' L. Enter the discharged quantity to calculate the received amount after loss.';
                }
                $loss = intdiv($discharged + 100, 200);

                return 'Shipment quantity: '.number_format($shipment->expected_ml / 1000, 3).' L. Actual received after standard 0.5% loss: '.number_format(($discharged - $loss) / 1000, 3).' L.';
            }),
            Placeholder::make('preview')->label('Estimated loss and tank balance')->content(function (Get $get) use ($type): string {
                try {
                    $quantity = CargoService::millilitres((string) $get('quantity_litres'));
                } catch (CargoException) {
                    return 'Enter a valid quantity and select a tank to preview.';
                }
                $tank = Tank::find($get('tank_id'));
                if (! $tank) {
                    return 'Select a tank to preview.';
                }
                $loss = $type === 'inbound' ? intdiv($quantity + 100, 200) : 0;
                $projected = $tank->balance_ml + ($type === 'inbound' ? $quantity - $loss : -$quantity);

                return 'Estimated loss: '.number_format($loss / 1000, 3).' L; projected balance: '.number_format($projected / 1000, 3).' L. Final checks validate the full event timeline when saved.';
            }),
            DateTimePicker::make('occurred_at')->label('Event time')->required()->default(now()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', $this->getMovementType())->with(['shipment', 'truck', 'tank', 'commodity']))
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
}
