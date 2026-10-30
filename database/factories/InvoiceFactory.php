<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'merchant_id' => fn (array $attributes) => Subscription::find($attributes['subscription_id'])->merchant_id,
            'customer_id' => fn (array $attributes) => Subscription::find($attributes['subscription_id'])->customer_id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-11-01',
            'currency' => 'INR',
            'base_amount_minor' => 99900,
            'overage_amount_minor' => 0,
            'total_minor' => 99900,
            'status' => InvoiceStatus::Draft,
        ];
    }
}
