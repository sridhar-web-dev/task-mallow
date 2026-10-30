<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\DailyUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyUsage>
 */
class DailyUsageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'merchant_id' => fn (array $attributes) => Customer::find($attributes['customer_id'])->merchant_id,
            'usage_date' => '2026-10-15',
            'units' => fake()->numberBetween(1, 1000),
        ];
    }
}
