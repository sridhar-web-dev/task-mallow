<?php

namespace App\Services\Dashboard;

use App\DTOs\BillingPeriod;
use App\DTOs\BillingSegment;
use App\DTOs\PlanPricing;
use App\Enums\SubscriptionStatus;
use App\Models\DailyUsage;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use App\Services\Billing\InvoiceCalculator;
use App\Services\PlanCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Forecasts the overage on each active subscription's current period by feeding
 * projected usage through the same calculator that produces real invoices.
 */
class OverageProjector
{
    /**
     * Plans seen in this run. A merchant has a few plans but thousands of
     * subscriptions, and each cache read is a round trip.
     *
     * @var array<int, PlanPricing>
     */
    private array $plans = [];

    public function __construct(
        private InvoiceCalculator $calculator,
        private PlanCatalog $catalog,
    ) {}

    public function project(int $merchantId, CarbonImmutable $today): int
    {
        $this->plans = [];
        $total = 0;

        Subscription::query()
            ->where('merchant_id', $merchantId)
            ->where('status', SubscriptionStatus::Active)
            ->with('segments')
            ->chunkById(config('billing.chunk_size'), function (EloquentCollection $subscriptions) use (&$total, $today) {
                $usage = $this->usageByCustomer($subscriptions);

                foreach ($subscriptions as $subscription) {
                    $total += $this->projectSubscription(
                        $subscription,
                        $usage->get($subscription->customer_id, collect()),
                        $today,
                    );
                }
            });

        return $total;
    }

    /**
     * @param  EloquentCollection<int, Subscription>  $subscriptions
     */
    private function usageByCustomer(EloquentCollection $subscriptions): Collection
    {
        // Filtering on merchant and date first lets the covering dashboard index
        // serve this as a range scan; without them it scanned the whole index.
        return DailyUsage::query()
            ->where('merchant_id', $subscriptions->first()->merchant_id)
            ->whereBetween('usage_date', [
                $subscriptions->min('current_period_start')->toDateString(),
                $subscriptions->max('current_period_end')->toDateString(),
            ])
            ->whereIn('customer_id', $subscriptions->pluck('customer_id'))
            ->toBase()
            ->get(['customer_id', 'usage_date', 'units'])
            ->groupBy('customer_id');
    }

    private function projectSubscription(Subscription $subscription, Collection $usage, CarbonImmutable $today): int
    {
        $period = new BillingPeriod($subscription->current_period_start, $subscription->current_period_end);
        $elapsedDays = $this->elapsedDays($period, $today);

        if ($elapsedDays === 0) {
            return 0;
        }

        $inPeriod = $this->within($usage, $period);
        $periodUnits = (int) $inPeriod->sum('units');

        $segments = $subscription->segments
            ->filter(fn (SubscriptionSegment $segment) => $segment->starts_on->greaterThanOrEqualTo($period->start)
                && $segment->starts_on->lessThan($period->end))
            ->map(fn (SubscriptionSegment $segment) => $this->projectSegment($segment, $period, $inPeriod, $periodUnits, $elapsedDays, $today))
            ->values()
            ->all();

        $plan = $this->plan($subscription->plan_id);

        return $this->calculator
            ->calculate(BillingPeriod::containing($plan->billingCycle, $period->start), $segments)
            ->overageAmountMinor;
    }

    /**
     * Actual usage so far, plus the period's daily average for each day left.
     * The whole period sets the average, so a scheduled segment that has not
     * started yet still gets a forecast.
     */
    private function projectSegment(
        SubscriptionSegment $segment,
        BillingPeriod $period,
        Collection $usage,
        int $periodUnits,
        int $elapsedDays,
        CarbonImmutable $today,
    ): BillingSegment {
        $range = new BillingPeriod($segment->starts_on, $segment->ends_on ?? $period->end);

        $actual = (int) $this->within($usage, $range)->sum('units');

        $firstRemainingDay = $range->start->max($today->addDay());
        $remainingDays = max(0, (int) $firstRemainingDay->diffInDays($range->end));

        return new BillingSegment(
            planName: $this->plan($segment->plan_id)->name,
            period: $range,
            basePriceMinor: $segment->base_price_minor,
            includedUnits: $segment->included_units,
            overageRateMicros: $segment->overage_rate_micros,
            usageUnits: $actual + intdiv($periodUnits * $remainingDays, $elapsedDays),
        );
    }

    private function plan(int $planId): PlanPricing
    {
        return $this->plans[$planId] ??= $this->catalog->find($planId);
    }

    private function within(Collection $usage, BillingPeriod $period): Collection
    {
        $start = $period->start->toDateString();
        $end = $period->end->toDateString();

        return $usage->filter(fn (object $day) => $day->usage_date >= $start && $day->usage_date < $end);
    }

    private function elapsedDays(BillingPeriod $period, CarbonImmutable $today): int
    {
        if ($today->lessThan($period->start)) {
            return 0;
        }

        return min($period->days(), (int) $period->start->diffInDays($today) + 1);
    }
}
