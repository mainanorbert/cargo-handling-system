<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tank extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'capacity_ml'];

    public function commodity(): BelongsTo
    {
        return $this->belongsTo(Commodity::class);
    }
}
