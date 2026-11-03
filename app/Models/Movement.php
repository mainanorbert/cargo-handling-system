<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Movement extends Model
{
    use HasFactory;

    protected $fillable = ['event_reference', 'type', 'status', 'tank_id', 'commodity_id', 'shipment_id', 'truck_id', 'source_ml', 'loss_ml', 'quantity_ml', 'loss_basis_points', 'occurred_at', 'received_at'];

    public function tank(): BelongsTo
    {
        return $this->belongsTo(Tank::class);
    }

    public function commodity(): BelongsTo
    {
        return $this->belongsTo(Commodity::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }
}
