<?php

namespace Database\Seeders;

use App\Models\Commodity;
use App\Models\Shipment;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['CPO', 'Crude Palm Oil', true], ['CPOL', 'Crude Palm Olein', false], ['PFAD', 'Palm Fatty Acid Distillate', false]] as [$code, $description, $control]) {
            Commodity::updateOrCreate(['code' => $code], ['description' => $description, 'requires_temperature_control' => $control]);
        }
        $vessel = Vessel::firstOrCreate(['name' => 'MV Palm Star']);
        Truck::updateOrCreate(['registration' => 'KAA 123A'], ['can_plate' => 'CAB 123A', 'trail_plate' => 'TRL 456B']);
        Tank::firstOrCreate(['name' => 'Tank A'], ['capacity_ml' => 100000000]);
        Tank::firstOrCreate(['name' => 'Tank B'], ['capacity_ml' => 200000000]);
        Shipment::firstOrCreate(['reference' => 'SHIP-DEMO-001'], ['origin' => 'Port of origin', 'vessel_id' => $vessel->id, 'commodity_id' => Commodity::where('code', 'CPO')->firstOrFail()->id, 'expected_ml' => 100000000]);
        if (app()->environment('local', 'testing')) {
            User::updateOrCreate(
                ['email' => 'bulk_admin@gmail'],
                ['name' => 'Bulk Admin', 'password' => 'admin@2026'],
            );
        }
    }
}
