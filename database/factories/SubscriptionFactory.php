<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'customer_id' => fn (array $attributes) => Customer::factory()->state(['merchant_id' => $attributes['merchant_id']]),
            'plan_id' => fn (array $attributes) => Plan::factory()->state(['merchant_id' => $attributes['merchant_id']]),
            'status' => SubscriptionStatus::Active,
            'started_on' => '2026-10-01',
            'current_period_start' => '2026-10-01',
            'current_period_end' => '2026-11-01',
        ];
    }
}
