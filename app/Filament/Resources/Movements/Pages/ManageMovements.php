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
use Illuminate\Validation\ValidationException;

class ManageMovements extends ManageRecords
{
    protected static string $resource = MovementResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->movementAction('inbound', 'Record vessel discharge'), $this->movementAction('outbound', 'Record truck delivery')];
    }

    private function movementAction(string $type, string $label): Action
    {
        return Action::make($type)->label($label)->schema([
            TextInput::make('event_reference')->label('Unique event reference')->required()->maxLength(255),
            Select::make('shipment_id')->label('Inbound shipment')->options(fn () => Shipment::pluck('reference', 'id'))->searchable()->required()->visible($type === 'inbound'),
            Select::make('truck_id')->label('Truck')->options(fn () => Truck::pluck('registration', 'id'))->searchable()->required()->visible($type === 'outbound'),
            Select::make('tank_id')->label('Shore tank')->options(fn () => Tank::all()->mapWithKeys(fn ($tank) => [$tank->id => $tank->name.' — '.number_format($tank->balance_ml / 1000, 3).' / '.number_format($tank->capacity_ml / 1000, 3).' L']))->searchable()->required()->live(),
            TextInput::make('quantity_litres')->label($type === 'inbound' ? 'Discharged quantity (L)' : 'Delivery quantity (L)')->required()->numeric()->minValue(0.001)->step(0.001)->live(onBlur: true)->helperText($type === 'inbound' ? 'A 0.5% loss is applied before the tank is credited.' : 'No inbound loss is applied to deliveries.'),
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

                return 'Estimated loss: '.number_format($loss / 1000, 3).' L; projected balance: '.number_format($projected / 1000, 3).' L. Final checks run when saved.';
            }),
            DateTimePicker::make('occurred_at')->label('Event time')->required()->default(now()),
        ])->action(function (array $data) use ($type): void {
            try {
                $movement = app(CargoService::class)->record([...$data, 'type' => $type]);
            } catch (CargoException $exception) {
                throw ValidationException::withMessages(['data.quantity_litres' => $exception->getMessage()]);
            }
            Notification::make()->title($movement->status === 'applied' ? 'Movement applied' : 'Late event recorded for review; stock unchanged')->success()->send();
        });
    }
}
