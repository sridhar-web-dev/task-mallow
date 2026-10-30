<?php

namespace App\Services\Billing;

use App\DTOs\BillingPeriod;
use App\DTOs\BillingSegment;
use App\DTOs\InvoiceCalculation;
use App\DTOs\InvoiceLineData;
use App\DTOs\PlanPricing;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\DailyUsage;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use App\Services\PlanCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class InvoiceGenerator
{
    public function __construct(
        private InvoiceCalculator $calculator,
        private PlanCatalog $catalog,
    ) {}

    /**
     * Subscriptions whose period ended at least the late window ago, by which
     * point POST /usage rejects any more events for that period.
     *
     * @return Builder<Subscription>
     */
    public function due(): Builder
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->where('current_period_end', '<=', $this->cutoff()->toDateString());
    }

    /**
     * Returns null when the subscription is no longer due. The row lock and that
     * check make a retried or duplicated job a no-op, and the unique index on
     * invoices is the last line of defence.
     */
    public function generate(int $subscriptionId): ?Invoice
    {
        return DB::transaction(function () use ($subscriptionId) {
            $subscription = $this->due()->whereKey($subscriptionId)->lockForUpdate()->first();

            if ($subscription === null) {
                return null;
            }

            $plan = $this->catalog->find($subscription->plan_id);
            $period = new BillingPeriod($subscription->current_period_start, $subscription->current_period_end);
            $cycle = BillingPeriod::containing($plan->billingCycle, $period->start);

            $segments = $subscription->segments()
                ->where('starts_on', '>=', $period->start->toDateString())
                ->where('starts_on', '<', $period->end->toDateString())
                ->orderBy('starts_on')
                ->get();

            $calculation = $this->calculator->calculate(
                $cycle,
                $segments->map(fn (SubscriptionSegment $segment) => $this->toBillingSegment($subscription, $segment, $period))->all(),
            );

            $invoice = $this->store($subscription, $period, $calculation);

            $segments->whereNull('ends_on')->each->update(['ends_on' => $period->end]);

            if ($subscription->canceled_at !== null) {
                $subscription->update(['status' => SubscriptionStatus::Canceled]);
            } else {
                $this->startNextPeriod($subscription, $plan, $period);
            }

            return $invoice;
        });
    }

    private function toBillingSegment(Subscription $subscription, SubscriptionSegment $segment, BillingPeriod $period): BillingSegment
    {
        $range = new BillingPeriod($segment->starts_on, $segment->ends_on ?? $period->end);

        $usage = DailyUsage::where('customer_id', $subscription->customer_id)
            ->where('usage_date', '>=', $range->start->toDateString())
            ->where('usage_date', '<', $range->end->toDateString())
            ->sum('units');

        return new BillingSegment(
            planName: $this->catalog->find($segment->plan_id)->name,
            period: $range,
            basePriceMinor: $segment->base_price_minor,
            includedUnits: $segment->included_units,
            overageRateMicros: $segment->overage_rate_micros,
            usageUnits: (int) $usage,
            segmentId: $segment->id,
        );
    }

    private function store(Subscription $subscription, BillingPeriod $period, InvoiceCalculation $calculation): Invoice
    {
        $invoice = Invoice::create([
            'merchant_id' => $subscription->merchant_id,
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'period_start' => $period->start,
            'period_end' => $period->end,
            'currency' => $subscription->merchant->currency,
            'base_amount_minor' => $calculation->baseAmountMinor,
            'overage_amount_minor' => $calculation->overageAmountMinor,
            'total_minor' => $calculation->totalMinor,
            'status' => InvoiceStatus::Issued,
            'issued_at' => now(),
        ]);

        $invoice->lines()->createMany(array_map(fn (InvoiceLineData $line) => [
            'subscription_segment_id' => $line->segmentId,
            'type' => $line->type,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'rate_micros' => $line->rateMicros,
            'proration_days' => $line->prorationDays,
            'cycle_days' => $line->cycleDays,
            'amount_minor' => $line->amountMinor,
        ], $calculation->lines));

        return $invoice;
    }

    private function startNextPeriod(Subscription $subscription, PlanPricing $plan, BillingPeriod $period): void
    {
        $next = BillingPeriod::containing($plan->billingCycle, $period->end);

        $subscription->update([
            'current_period_start' => $next->start,
            'current_period_end' => $next->end,
        ]);

        $subscription->segments()->create([
            'plan_id' => $plan->id,
            'starts_on' => $next->start,
            'base_price_minor' => $plan->basePriceMinor,
            'included_units' => $plan->includedUnits,
            'overage_rate_micros' => $plan->overageRateMicros,
        ]);
    }

    private function cutoff(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfDay()->subDays(config('billing.usage.late_window_days'));
    }
}
