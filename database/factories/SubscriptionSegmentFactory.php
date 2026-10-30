<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionSegment>
 */
class SubscriptionSegmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'plan_id' => fn (array $attributes) => Subscription::find($attributes['subscription_id'])->plan_id,
            'starts_on' => '2026-10-01',
            'ends_on' => null,
            'base_price_minor' => 99900,
            'included_units' => 10000,
            'overage_rate_micros' => 50000,
        ];
    }
}
