<?php

namespace App\Services;

use App\Exceptions\CargoException;
use App\Exceptions\TankCapacityException;
use App\Models\Movement;
use App\Models\Shipment;
use App\Models\Tank;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CargoService
{
    /** Convert litres to integer millilitres, rejecting invalid or excessive precision. */
    public static function millilitres(string $litres): int
    {
        if (! preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,3})?$/', $litres)) {
            throw new CargoException('Enter a valid quantity in litres with at most three decimal places.');
        }
        [$whole, $fraction] = array_pad(explode('.', $litres, 2), 2, '');
        $amount = (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
        if ($amount <= 0) {
            throw new CargoException('Quantity must be greater than zero.');
        }

        return $amount;
    }

    /** @param array<string, mixed> $data */
    public function record(array $data): Movement
    {
        if (isset($data['quantity_litres']) && (is_int($data['quantity_litres']) || is_float($data['quantity_litres']))) {
            $data['quantity_litres'] = (string) $data['quantity_litres'];
        }

        $data = Validator::make($data, [
            'event_reference' => ['required', 'string', 'max:255'], 'type' => ['required', 'in:inbound,outbound'],
            'tank_id' => ['required', 'integer', 'exists:tanks,id'], 'shipment_id' => ['required_if:type,inbound', 'nullable', 'integer', 'exists:shipments,id'],
            'truck_id' => ['required_if:type,outbound', 'nullable', 'integer', 'exists:trucks,id'], 'quantity_litres' => ['required', 'string'], 'occurred_at' => ['required', 'date'],
        ])->validate();
        $quantity = self::millilitres($data['quantity_litres']);

        return DB::transaction(function () use ($data, $quantity): Movement {
            $tank = Tank::query()->lockForUpdate()->findOrFail($data['tank_id']);
            if (Movement::where('event_reference', $data['event_reference'])->exists()) {
                throw new CargoException('This event reference has already been recorded.');
            }
            $inbound = $data['type'] === 'inbound';
            $shipment = $inbound ? Shipment::findOrFail($data['shipment_id']) : null;
            $commodityId = $shipment?->commodity_id ?? $tank->commodity_id;
            if (! $commodityId) {
                throw new CargoException('The tank has no stock available for delivery.');
            }
            $occurred = Carbon::parse($data['occurred_at']);
            $latest = Movement::where('tank_id', $tank->id)->where('status', 'applied')->orderByDesc('occurred_at')->orderByDesc('event_reference')->first();
            $late = $latest && ($occurred->lt(Carbon::parse($latest->occurred_at)) || ($occurred->equalTo(Carbon::parse($latest->occurred_at)) && strcmp($data['event_reference'], $latest->event_reference) < 0));
            // Round the 0.5% loss to the nearest millilitre, with half rounded up.
            $loss = $inbound ? intdiv($quantity + 100, 200) : 0;
            $net = $quantity - $loss;
            if (! $late) {
                if ($inbound && $tank->balance_ml > 0 && $tank->commodity_id !== $commodityId) {
                    throw new CargoException('The tank contains a different commodity.');
                }
                if ($inbound && $net > $tank->capacity_ml - $tank->balance_ml) {
                    throw new TankCapacityException('The received quantity exceeds the tank capacity.');
                }
                if (! $inbound && $net > $tank->balance_ml) {
                    throw new CargoException('The delivery exceeds the available stock.');
                }
                if ($net <= 0) {
                    throw new CargoException('The received quantity must be greater than zero after loss.');
                }
                $tank->balance_ml = $inbound ? $tank->balance_ml + $net : $tank->balance_ml - $net;
                $tank->commodity_id = $tank->balance_ml ? $commodityId : null;
                $tank->save();
            }

            return Movement::create(['event_reference' => $data['event_reference'], 'type' => $data['type'], 'status' => $late ? 'needs_review' : 'applied', 'tank_id' => $tank->id, 'commodity_id' => $commodityId, 'shipment_id' => $shipment?->id, 'truck_id' => $inbound ? null : $data['truck_id'], 'source_ml' => $quantity, 'loss_ml' => $loss, 'quantity_ml' => $net, 'loss_basis_points' => $inbound ? 50 : 0, 'occurred_at' => $occurred, 'received_at' => now()]);
        }, attempts: 3);
    }
}
