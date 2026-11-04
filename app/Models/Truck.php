<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Truck extends Model
{
    use HasFactory;

    protected $fillable = [
        'registration',
        'can_plate',
        'trail_plate',
    ];

    protected static function booted(): void
    {
        static::creating(function (Truck $truck): void {
            if ($truck->registration === null || $truck->registration === '') {
                $truck->registration = 'internal-'.Str::uuid();
            }
        });
    }
}
