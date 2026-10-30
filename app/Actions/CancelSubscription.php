<?php

namespace App\Actions;

use App\Models\Subscription;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelSubscription
{
    /**
     * Ends the period early; $endsOn is exclusive, like every range here. The
     * subscription stays active until its final invoice marks it canceled.
     */
    public function handle(Subscription $subscription, CarbonImmutable $endsOn): Subscription
    {
        return DB::transaction(function () use ($subscription, $endsOn) {
            $subscription = Subscription::lockForUpdate()->findOrFail($subscription->id);

            if ($subscription->canceled_at !== null) {
                throw new DomainException('This subscription is already canceled.');
            }

            $segment = $subscription->segments()->whereNull('ends_on')->firstOrFail();

            if ($endsOn->lessThanOrEqualTo($segment->starts_on) || $endsOn->greaterThan($subscription->current_period_end)) {
                throw new DomainException('A cancellation must fall inside the current segment.');
            }

            $segment->update(['ends_on' => $endsOn]);

            $subscription->update([
                'current_period_end' => $endsOn,
                'canceled_at' => now(),
            ]);

            return $subscription;
        });
    }
}
