<?php

namespace App\Actions;

use App\DTOs\PlanPricing;
use App\Exceptions\PlanChangeConflict;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use App\Services\PlanCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangePlan
{
    public function __construct(private PlanCatalog $catalog) {}

    /**
     * Splits the open segment at the effective date. Changes start at 00:00 UTC
     * and never earlier than tomorrow, so a day's usage belongs to one plan and
     * usage already recorded stays on the plan it was recorded under.
     */
    public function handle(Subscription $subscription, int $planId, ?CarbonImmutable $effectiveOn = null): SubscriptionSegment
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $effectiveOn ??= $today->addDay();

        $plan = $this->catalog->find($planId);

        if ($plan === null || $plan->merchantId !== $subscription->merchant_id) {
            throw ValidationException::withMessages(['plan_id' => 'This plan does not belong to the merchant.']);
        }

        if (! $plan->isActive) {
            throw ValidationException::withMessages(['plan_id' => 'This plan is no longer available.']);
        }

        return DB::transaction(function () use ($subscription, $plan, $effectiveOn, $today) {
            $subscription = Subscription::lockForUpdate()->findOrFail($subscription->id);

            if ($subscription->canceled_at !== null) {
                throw new PlanChangeConflict('A canceled subscription cannot change plan.');
            }

            $this->ensureComparablePlan($subscription, $plan);
            $this->ensureValidDate($subscription, $effectiveOn, $today);

            $openSegment = $subscription->segments()->whereNull('ends_on')->firstOrFail();

            // Also covers two changes for one date, which the segment index forbids.
            if ($effectiveOn->lessThanOrEqualTo($openSegment->starts_on)) {
                throw new PlanChangeConflict('A plan change is already scheduled for this date or later.');
            }

            $openSegment->update(['ends_on' => $effectiveOn]);
            $subscription->update(['plan_id' => $plan->id]);

            return $subscription->segments()->create([
                'plan_id' => $plan->id,
                'starts_on' => $effectiveOn,
                'base_price_minor' => $plan->basePriceMinor,
                'included_units' => $plan->includedUnits,
                'overage_rate_micros' => $plan->overageRateMicros,
            ]);
        });
    }

    /**
     * A cycle is prorated against one length, so a monthly and a yearly plan
     * cannot share one; that switch has to happen by canceling and resubscribing.
     */
    private function ensureComparablePlan(Subscription $subscription, PlanPricing $plan): void
    {
        $current = $this->catalog->find($subscription->plan_id);

        if ($current->id === $plan->id) {
            throw ValidationException::withMessages(['plan_id' => 'The subscription is already on this plan.']);
        }

        if ($current->billingCycle !== $plan->billingCycle) {
            throw ValidationException::withMessages(['plan_id' => 'A plan change cannot switch billing cycle.']);
        }
    }

    private function ensureValidDate(Subscription $subscription, CarbonImmutable $effectiveOn, CarbonImmutable $today): void
    {
        if ($effectiveOn->lessThanOrEqualTo($today)) {
            throw ValidationException::withMessages(['effective_on' => 'A plan change must start after today.']);
        }

        if ($effectiveOn->greaterThanOrEqualTo($subscription->current_period_end)) {
            throw ValidationException::withMessages(['effective_on' => 'A plan change must fall inside the current billing period.']);
        }
    }
}
