<?php

namespace App\Services;

use App\Exceptions\CargoException;
use App\Exceptions\ShipmentQuantityExceededException;
use App\Exceptions\TankCapacityException;
use App\Models\Movement;
use App\Models\Shipment;
use App\Models\Tank;
use Illuminate\Database\UniqueConstraintViolationException;
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
        return $this->recordBatch([$data])[0];
    }

    /**
     * Validate a complete batch and each affected timeline before committing any stock changes.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<Movement>
     */
    public function recordBatch(array $events): array
    {
        if ($events === []) {
            throw new CargoException('Provide at least one cargo event.');
        }

        $events = array_map($this->validateEvent(...), $events);
        $references = array_column($events, 'event_reference');
        if (count(array_unique($references)) !== count($references)) {
            throw new CargoException('This event reference has already been recorded.');
        }

        try {
            return DB::transaction(function () use ($events, $references): array {
                $tanks = Tank::query()->whereIn('id', array_column($events, 'tank_id'))
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                if ($tanks->count() !== count(array_unique(array_column($events, 'tank_id')))) {
                    throw new CargoException('A selected tank no longer exists.');
                }

                $shipmentIds = array_values(array_unique(array_filter(array_column($events, 'shipment_id'))));
                $shipments = Shipment::query()
                    ->whereIn('id', $shipmentIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($shipments->count() !== count($shipmentIds)) {
                    throw new CargoException('A selected shipment no longer exists.');
                }

                if (Movement::whereIn('event_reference', $references)->exists()) {
                    throw new CargoException('This event reference has already been recorded.');
                }

                $dischargedByShipment = Movement::query()
                    ->select('shipment_id')
                    ->selectRaw('SUM(source_ml) as discharged_ml')
                    ->whereIn('shipment_id', $shipmentIds)
                    ->where('type', 'inbound')
                    ->where('status', 'applied')
                    ->groupBy('shipment_id')
                    ->pluck('discharged_ml', 'shipment_id');
                $remainingByShipment = $shipments->mapWithKeys(function (Shipment $shipment) use ($dischargedByShipment): array {
                    $alreadyDischarged = (int) $dischargedByShipment->get($shipment->id, 0);

                    return [$shipment->id => max(0, (int) $shipment->expected_ml - $alreadyDischarged)];
                })->all();

                $newMovements = [];
                $recordedByUserId = auth()->id();
                foreach ($events as $event) {
                    $inbound = $event['type'] === 'inbound';
                    $shipment = $inbound ? $shipments->get($event['shipment_id']) : null;
                    $quantity = self::millilitres($event['quantity_litres']);
                    if ($shipment && $quantity > $remainingByShipment[$shipment->id]) {
                        throw new ShipmentQuantityExceededException(
                            'Quantity cannot exceed the amount remaining in the selected shipment ('
                            .number_format($remainingByShipment[$shipment->id] / 1000, 3).' L remains).'
                        );
                    }
                    if ($shipment) {
                        $remainingByShipment[$shipment->id] -= $quantity;
                    }
                    $newMovements[] = new Movement([
                        'event_reference' => $event['event_reference'],
                        'type' => $event['type'], 'status' => 'applied',
                        'tank_id' => $tanks->get($event['tank_id'])->id,
                        'commodity_id' => $inbound ? $shipment?->commodity_id : ($event['commodity_id'] ?? null),
                        'shipment_id' => $shipment?->id,
                        'truck_id' => $inbound ? null : $event['truck_id'],
                        'source_ml' => $quantity, 'loss_ml' => 0,
                        'quantity_ml' => $quantity, 'loss_basis_points' => 0,
                        'occurred_at' => Carbon::parse($event['occurred_at'])->setTimezone(config('app.timezone'))->startOfSecond()->toDateTimeString(),
                        'received_at' => now(),
                        'recorded_by_user_id' => $recordedByUserId,
                    ]);
                }

                foreach ($tanks as $tank) {
                    $history = Movement::where('tank_id', $tank->id)->where('status', 'applied')->get()->all();
                    $previous = $this->replay($tank, $history);
                    if ($previous['balance'] !== (int) $tank->balance_ml || $previous['commodity_id'] !== ($tank->commodity_id === null ? null : (int) $tank->commodity_id)) {
                        throw new CargoException('The tank balance does not match its movement history. Review the tank before recording more cargo.');
                    }
                    $incoming = array_filter($newMovements, fn (Movement $movement): bool => (int) $movement->tank_id === (int) $tank->id);
                    $result = $this->replay($tank, [...$history, ...$incoming]);
                    $tank->balance_ml = $result['balance'];
                    $tank->commodity_id = $result['commodity_id'];
                }

                foreach ($tanks as $tank) {
                    $tank->save();
                }
                usort($newMovements, $this->compareMovements(...));
                foreach ($newMovements as $movement) {
                    $movement->save();
                }

                return $newMovements;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw new CargoException('This event reference has already been recorded.', previous: $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{event_reference: string, type: string, tank_id: int|string, shipment_id?: int|string|null, truck_id?: int|string|null, quantity_litres: string, occurred_at: string}
     */
    private function validateEvent(array $data): array
    {
        if (isset($data['quantity_litres']) && (is_int($data['quantity_litres']) || is_float($data['quantity_litres']))) {
            $data['quantity_litres'] = (string) $data['quantity_litres'];
        }

        $data = Validator::make($data, [
            'event_reference' => ['required', 'string', 'max:255'], 'type' => ['required', 'in:inbound,outbound'],
            'tank_id' => ['required', 'integer', 'exists:tanks,id'], 'shipment_id' => ['required_if:type,inbound', 'nullable', 'integer', 'exists:shipments,id'],
            'truck_id' => ['required_if:type,outbound', 'nullable', 'integer', 'exists:trucks,id'], 'quantity_litres' => ['required', 'string'], 'occurred_at' => ['required', 'date'],
            'commodity_id' => ['nullable', 'integer', 'exists:commodities,id'],
        ])->validate();
        self::millilitres($data['quantity_litres']);

        return $data;
    }

    /**
     * Replay from empty storage, preserving the recorded commodity and quantities of existing movements.
     *
     * @param  list<Movement>  $movements
     * @return array{balance: int, commodity_id: ?int}
     */
    private function replay(Tank $tank, array $movements): array
    {
        usort($movements, $this->compareMovements(...));
        $balance = 0;
        $commodityId = null;
        foreach ($movements as $movement) {
            $quantity = (int) $movement->quantity_ml;
            if ($quantity <= 0) {
                throw new CargoException('The movement quantity must be greater than zero.');
            }
            if ($movement->type === 'inbound') {
                if ($balance > 0 && $commodityId !== (int) $movement->commodity_id) {
                    throw new CargoException(
                        'Tank "'.$tank->name.'" already contains a different commodity. Empty the tank before receiving this shipment.'
                    );
                }
                if ($quantity > (int) $tank->capacity_ml - $balance) {
                    throw new TankCapacityException(
                        'Quantity cannot exceed the available capacity of tank "'.$tank->name.'" ('
                        .number_format(((int) $tank->capacity_ml - $balance) / 1000, 3).' L available).'
                    );
                }
                $balance += $quantity;
                $commodityId = (int) $movement->commodity_id;
            } else {
                if ($quantity > $balance) {
                    throw new CargoException('The delivery exceeds the available stock.');
                }
                if ($movement->commodity_id !== null && (int) $movement->commodity_id !== $commodityId) {
                    throw new CargoException('The event conflicts with the commodity of an existing delivery.');
                }
                if (! $movement->exists) {
                    $movement->commodity_id = $commodityId;
                }
                $balance -= $quantity;
                if ($balance === 0) {
                    $commodityId = null;
                }
            }
        }

        return ['balance' => $balance, 'commodity_id' => $commodityId];
    }

    private function compareMovements(Movement $left, Movement $right): int
    {
        return strcmp((string) $left->occurred_at, (string) $right->occurred_at)
            ?: strcmp($left->event_reference, $right->event_reference);
    }
}
