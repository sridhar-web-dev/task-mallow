<?php

namespace App\Actions;

use App\DTOs\BillingPeriod;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Subscription;
use App\Services\PlanCatalog;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class SubscribeCustomer
{
    public function __construct(private PlanCatalog $catalog) {}

    public function handle(Customer $customer, int $planId, CarbonImmutable $startsOn): Subscription
    {
        $plan = $this->catalog->find($planId);

        if ($plan === null || $plan->merchantId !== $customer->merchant_id) {
            throw new DomainException('This plan does not belong to the merchant.');
        }

        if (! $plan->isActive) {
            throw new DomainException('This plan is no longer available.');
        }

        return DB::transaction(function () use ($customer, $plan, $startsOn) {
            // Serializes concurrent subscribes for one customer.
            Customer::whereKey($customer->id)->lockForUpdate()->first();

            if ($customer->subscriptions()->where('status', SubscriptionStatus::Active)->exists()) {
                throw new DomainException('This customer already has an active subscription.');
            }

            $cycle = BillingPeriod::containing($plan->billingCycle, $startsOn);

            $subscription = $customer->subscriptions()->create([
                'merchant_id' => $customer->merchant_id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'started_on' => $startsOn,
                'current_period_start' => $startsOn,
                'current_period_end' => $cycle->end,
            ]);

            $subscription->segments()->create([
                'plan_id' => $plan->id,
                'starts_on' => $startsOn,
                'base_price_minor' => $plan->basePriceMinor,
                'included_units' => $plan->includedUnits,
                'overage_rate_micros' => $plan->overageRateMicros,
            ]);

            return $subscription;
        });
    }
}
