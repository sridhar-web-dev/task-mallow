<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rollup of usage_events. Billing and the dashboard read this table only.
        Schema::create('daily_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->date('usage_date');
            $table->unsignedBigInteger('units');
            $table->timestamps();

            // Upsert target, and the range scan for a customer's billing segment.
            $table->unique(['customer_id', 'usage_date']);
            // Covering index: dashboard totals never touch the table rows.
            $table->index(['merchant_id', 'usage_date', 'customer_id', 'units'], 'daily_usage_dashboard_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_usage');
    }
};
