<?php

namespace App\Services;

use App\Exceptions\CargoException;
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

                if (Movement::whereIn('event_reference', $references)->exists()) {
                    throw new CargoException('This event reference has already been recorded.');
                }

                $newMovements = [];
                foreach ($events as $event) {
                    $inbound = $event['type'] === 'inbound';
                    $shipment = $inbound ? Shipment::findOrFail($event['shipment_id']) : null;
                    $quantity = self::millilitres($event['quantity_litres']);
                    $loss = $inbound ? intdiv($quantity + 100, 200) : 0;
                    $newMovements[] = new Movement([
                        'event_reference' => $event['event_reference'],
                        'type' => $event['type'], 'status' => 'applied',
                        'tank_id' => $tanks->get($event['tank_id'])->id,
                        'commodity_id' => $shipment?->commodity_id,
                        'shipment_id' => $shipment?->id,
                        'truck_id' => $inbound ? null : $event['truck_id'],
                        'source_ml' => $quantity, 'loss_ml' => $loss,
                        'quantity_ml' => $quantity - $loss, 'loss_basis_points' => $inbound ? 50 : 0,
                        'occurred_at' => Carbon::parse($event['occurred_at'])->setTimezone(config('app.timezone'))->startOfSecond()->toDateTimeString(),
                        'received_at' => now(),
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
                throw new CargoException('The received quantity must be greater than zero after loss.');
            }
            if ($movement->type === 'inbound') {
                if ($balance > 0 && $commodityId !== (int) $movement->commodity_id) {
                    throw new CargoException('The tank contains a different commodity.');
                }
                if ($quantity > (int) $tank->capacity_ml - $balance) {
                    throw new TankCapacityException('The received quantity exceeds the tank capacity.');
                }
                $balance += $quantity;
                $commodityId = (int) $movement->commodity_id;
            } else {
                if ($quantity > $balance) {
                    throw new CargoException('The delivery exceeds the available stock.');
                }
                if ($movement->exists && (int) $movement->commodity_id !== $commodityId) {
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
