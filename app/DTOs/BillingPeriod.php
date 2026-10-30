<?php

namespace App\DTOs;

use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A half-open date range: [start, end).
 */
final readonly class BillingPeriod
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {
        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('A billing period must end after it starts.');
        }
    }

    /**
     * Cycles are calendar-aligned, so a subscription that starts on the 15th
     * has a short first period and is prorated against the full month.
     */
    public static function containing(BillingCycle $cycle, CarbonImmutable $date): self
    {
        $start = match ($cycle) {
            BillingCycle::Monthly => $date->startOfMonth(),
            BillingCycle::Yearly => $date->startOfYear(),
        };

        $end = match ($cycle) {
            BillingCycle::Monthly => $start->addMonthNoOverflow(),
            BillingCycle::Yearly => $start->addYearNoOverflow(),
        };

        return new self($start->startOfDay(), $end->startOfDay());
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end);
    }
}
