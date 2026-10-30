<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('subscription_id')->constrained();
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency', 3);
            $table->unsignedBigInteger('base_amount_minor');
            $table->unsignedBigInteger('overage_amount_minor');
            $table->unsignedBigInteger('total_minor');
            $table->string('status', 16);
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            // A retried billing job cannot invoice the same period twice.
            $table->unique(['subscription_id', 'period_start']);
            $table->index(['merchant_id', 'period_start']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_segment_id')->nullable()->constrained();
            $table->string('type', 16);
            $table->string('description');
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('rate_micros');
            $table->unsignedInteger('proration_days');
            $table->unsignedInteger('cycle_days');
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
