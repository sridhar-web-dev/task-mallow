<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Models\Merchant;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'name' => fake()->unique()->words(2, true),
            'billing_cycle' => BillingCycle::Monthly,
            'base_price_minor' => 99900,
            'included_units' => 10000,
            'overage_rate_micros' => 50000,
            'is_active' => true,
        ];
    }
}
