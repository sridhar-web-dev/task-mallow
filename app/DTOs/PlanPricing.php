<?php

namespace App\DTOs;

use App\Enums\BillingCycle;
use App\Models\Plan;

/**
 * The cached form of a plan. A plain value object keeps Eloquent out of the cache.
 */
final readonly class PlanPricing
{
    public function __construct(
        public int $id,
        public int $merchantId,
        public string $name,
        public BillingCycle $billingCycle,
        public int $basePriceMinor,
        public int $includedUnits,
        public int $overageRateMicros,
        public bool $isActive,
    ) {}

    public static function fromModel(Plan $plan): self
    {
        return new self(
            id: $plan->id,
            merchantId: $plan->merchant_id,
            name: $plan->name,
            billingCycle: $plan->billing_cycle,
            basePriceMinor: $plan->base_price_minor,
            includedUnits: $plan->included_units,
            overageRateMicros: $plan->overage_rate_micros,
            isActive: $plan->is_active,
        );
    }
}
