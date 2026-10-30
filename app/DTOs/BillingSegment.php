<?php

namespace App\DTOs;

/**
 * One stretch of a billing cycle on a single plan, with its usage.
 */
final readonly class BillingSegment
{
    public function __construct(
        public string $planName,
        public BillingPeriod $period,
        public int $basePriceMinor,
        public int $includedUnits,
        public int $overageRateMicros,
        public int $usageUnits,
        public ?int $segmentId = null,
    ) {}
}
