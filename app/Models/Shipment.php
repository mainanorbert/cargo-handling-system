<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    use HasFactory;

    public const MINIMUM_ACCEPTABLE_DISCHARGE_BASIS_POINTS = 9950;

    public const COMPLETE_DISCHARGE_BASIS_POINTS = 10000;

    protected $fillable = ['reference', 'origin', 'vessel_id', 'commodity_id', 'expected_ml'];

    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    public function commodity(): BelongsTo
    {
        return $this->belongsTo(Commodity::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    public function inboundMovements(): HasMany
    {
        return $this->movements()
            ->where('type', 'inbound')
            ->where('status', 'applied');
    }

    public function dischargedMillilitres(): int
    {
        if (array_key_exists('discharged_ml', $this->attributes)) {
            return (int) ($this->attributes['discharged_ml'] ?? 0);
        }

        return (int) $this->inboundMovements()->sum('source_ml');
    }

    public function remainingMillilitres(): int
    {
        return max(0, (int) $this->expected_ml - $this->dischargedMillilitres());
    }

    public function dischargePercentageBasisPoints(): int
    {
        return self::percentageBasisPoints($this->dischargedMillilitres(), (int) $this->expected_ml);
    }

    private static function formattedPercentage(int $quantity, int $total): string
    {
        $basisPoints = self::percentageBasisPoints($quantity, $total);

        return intdiv($basisPoints, 100).'.'.str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT).'%';
    }

    public static function percentageBasisPoints(int $quantity, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        $whole = intdiv($quantity, $total);
        $remainder = $quantity % $total;
        $decimalDigits = 0;

        for ($index = 0; $index < 4; $index++) {
            $remainder *= 10;
            $decimalDigits = ($decimalDigits * 10) + intdiv($remainder, $total);
            $remainder %= $total;
        }

        return ($whole * self::COMPLETE_DISCHARGE_BASIS_POINTS) + $decimalDigits;
    }

    public function formattedDischargePercentage(): string
    {
        return self::formattedPercentage($this->dischargedMillilitres(), (int) $this->expected_ml);
    }

    public function formattedUnaccountedPercentage(): string
    {
        return self::formattedPercentage(
            max(0, (int) $this->expected_ml - $this->dischargedMillilitres()),
            (int) $this->expected_ml,
        );
    }

    public function meetsDischargeExpectation(): bool
    {
        $basisPoints = $this->dischargePercentageBasisPoints();

        return $basisPoints >= self::MINIMUM_ACCEPTABLE_DISCHARGE_BASIS_POINTS
            && $basisPoints <= self::COMPLETE_DISCHARGE_BASIS_POINTS;
    }
}
