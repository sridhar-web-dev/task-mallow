<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            // Denormalized from customers so tenant queries avoid a join.
            $table->foreignId('merchant_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('plan_id')->constrained();
            $table->string('status', 16);
            $table->date('started_on');
            // Half-open period: [current_period_start, current_period_end).
            $table->date('current_period_start');
            $table->date('current_period_end');
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            // Billing sweep: active subscriptions whose period has ended.
            $table->index(['status', 'current_period_end']);
            $table->index(['merchant_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        // One row per stretch of a billing period on a single plan. Pricing is
        // snapshotted here so editing a plan never rewrites an open period.
        Schema::create('subscription_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained();
            $table->foreignId('plan_id')->constrained();
            // Half-open range; ends_on is null for the open segment.
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedBigInteger('base_price_minor');
            $table->unsignedBigInteger('included_units');
            $table->unsignedBigInteger('overage_rate_micros');
            $table->timestamps();

            $table->unique(['subscription_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_segments');
        Schema::dropIfExists('subscriptions');
    }
};
