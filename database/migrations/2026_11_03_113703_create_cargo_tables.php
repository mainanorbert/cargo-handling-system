<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commodities', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('description');
            $table->boolean('requires_temperature_control')->default(false);
            $table->timestamps();
        });
        Schema::create('vessels', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::create('trucks', function (Blueprint $table) {
            $table->id();
            $table->string('registration')->unique();
            $table->timestamps();
        });
        Schema::create('tanks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('capacity_ml');
            $table->unsignedBigInteger('balance_ml')->default(0);
            $table->foreignId('commodity_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('origin');
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->foreignId('commodity_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('expected_ml');
            $table->timestamps();
        });
        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->string('event_reference')->unique();
            $table->string('type');
            $table->string('status');
            $table->foreignId('tank_id')->constrained()->restrictOnDelete();
            $table->foreignId('commodity_id')->constrained()->restrictOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('truck_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('source_ml');
            $table->unsignedBigInteger('loss_ml');
            $table->unsignedBigInteger('quantity_ml');
            $table->unsignedInteger('loss_basis_points');
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->timestamps();
            $table->index(['tank_id', 'status', 'occurred_at', 'event_reference']);
        });
    }

    public function down(): void
    {
        foreach (['movements', 'shipments', 'tanks', 'trucks', 'vessels', 'commodities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
