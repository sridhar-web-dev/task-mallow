<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained();
            // The merchant's own identifier, used by POST /usage.
            $table->string('external_id', 64);
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
