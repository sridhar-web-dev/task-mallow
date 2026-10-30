<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained();
            $table->string('name');
            $table->string('billing_cycle', 16);
            $table->unsignedBigInteger('base_price_minor');
            $table->unsignedBigInteger('included_units');
            // Millionths of the major unit, so per-call rates below one paisa stay exact.
            $table->unsignedBigInteger('overage_rate_micros');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['merchant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
