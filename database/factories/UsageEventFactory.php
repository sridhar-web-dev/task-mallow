<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\UsageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UsageEvent>
 */
class UsageEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'merchant_id' => fn (array $attributes) => Customer::find($attributes['customer_id'])->merchant_id,
            'usage_date' => '2026-10-15',
            'quantity' => fake()->numberBetween(1, 100),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
