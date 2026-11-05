<?php

namespace App\Providers;

use App\Models\Commodity;
use App\Models\Movement;
use App\Models\Shipment;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\Vessel;
use App\Observers\AuditObserver;
use App\Observers\MovementObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Movement::observe(MovementObserver::class);
        foreach ([Commodity::class, Vessel::class, Truck::class, Tank::class, Shipment::class, Movement::class] as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
