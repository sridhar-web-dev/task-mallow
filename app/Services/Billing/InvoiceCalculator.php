<?php

namespace App\Services\Billing;

use App\DTOs\BillingPeriod;
use App\DTOs\BillingSegment;
use App\DTOs\InvoiceCalculation;
use App\DTOs\InvoiceLineData;
use App\Enums\InvoiceLineType;
use InvalidArgumentException;
use OverflowException;

/**
 * Pure billing math: no database, clock or framework dependencies.
 *
 * Each segment is billed on its own, so a plan change splits the base charge,
 * the allowance and the overage rate at the change date. Unused allowance does
 * not carry across segments.
 */
class InvoiceCalculator
{
    private const MICROS_PER_MINOR = 10_000;

    /**
     * @param  list<BillingSegment>  $segments
     */
    public function calculate(BillingPeriod $cycle, array $segments): InvoiceCalculation
    {
        $segments = $this->ordered($cycle, $segments);
        $cycleDays = $cycle->days();

        $lines = [];
        $baseAmountMinor = 0;
        $overageAmountMinor = 0;

        foreach ($segments as $segment) {
            $days = $segment->period->days();

            $base = $this->baseLine($segment, $days, $cycleDays);
            $baseAmountMinor += $base->amountMinor;
            $lines[] = $base;

            $overage = $this->overageLine($segment, $days, $cycleDays);

            if ($overage !== null) {
                $overageAmountMinor += $overage->amountMinor;
                $lines[] = $overage;
            }
        }

        return new InvoiceCalculation(
            $lines,
            $baseAmountMinor,
            $overageAmountMinor,
            $baseAmountMinor + $overageAmountMinor,
        );
    }

    private function baseLine(BillingSegment $segment, int $days, int $cycleDays): InvoiceLineData
    {
        return new InvoiceLineData(
            type: InvoiceLineType::Base,
            description: sprintf('%s base charge (%d/%d days)', $segment->planName, $days, $cycleDays),
            quantity: 1,
            rateMicros: $this->multiply($segment->basePriceMinor, self::MICROS_PER_MINOR),
            prorationDays: $days,
            cycleDays: $cycleDays,
            amountMinor: $this->divideRoundingHalfUp($this->multiply($segment->basePriceMinor, $days), $cycleDays),
            segmentId: $segment->segmentId,
        );
    }

    private function overageLine(BillingSegment $segment, int $days, int $cycleDays): ?InvoiceLineData
    {
        $allowance = intdiv($this->multiply($segment->includedUnits, $days), $cycleDays);
        $overageUnits = $segment->usageUnits - $allowance;

        if ($overageUnits <= 0) {
            return null;
        }

        return new InvoiceLineData(
            type: InvoiceLineType::Overage,
            description: sprintf('%s overage (%d units over %d included)', $segment->planName, $overageUnits, $allowance),
            quantity: $overageUnits,
            rateMicros: $segment->overageRateMicros,
            prorationDays: $days,
            cycleDays: $cycleDays,
            amountMinor: $this->divideRoundingHalfUp(
                $this->multiply($overageUnits, $segment->overageRateMicros),
                self::MICROS_PER_MINOR,
            ),
            segmentId: $segment->segmentId,
        );
    }

    /**
     * @param  list<BillingSegment>  $segments
     * @return list<BillingSegment>
     */
    private function ordered(BillingPeriod $cycle, array $segments): array
    {
        if ($segments === []) {
            throw new InvalidArgumentException('An invoice needs at least one segment.');
        }

        usort($segments, fn (BillingSegment $a, BillingSegment $b) => $a->period->start <=> $b->period->start);

        $previous = null;

        foreach ($segments as $segment) {
            if ($segment->period->start->lessThan($cycle->start) || $segment->period->end->greaterThan($cycle->end)) {
                throw new InvalidArgumentException('Segments must fall inside the billing cycle.');
            }

            if ($previous !== null && ! $previous->period->end->equalTo($segment->period->start)) {
                throw new InvalidArgumentException('Segments must be contiguous, with no gaps or overlaps.');
            }

            $previous = $segment;
        }

        return $segments;
    }

    /**
     * PHP silently turns an overflowing integer into a float, which would
     * corrupt an invoice, so this fails loudly instead.
     */
    private function multiply(int $a, int $b): int
    {
        $product = $a * $b;

        if (! is_int($product)) {
            throw new OverflowException("Billing amount overflow: {$a} x {$b}.");
        }

        return $product;
    }

    private function divideRoundingHalfUp(int $dividend, int $divisor): int
    {
        $quotient = intdiv($dividend, $divisor);
        $remainder = $dividend % $divisor;

        return $remainder >= $divisor - $remainder ? $quotient + 1 : $quotient;
    }
}
