<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Services\Billing\UsageAggregator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Takes a final rollup for a chunk of due subscriptions, then invoices each one
 * in its own job, so one bad subscription cannot block the rest of the chunk.
 */
class CloseBillingChunk implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  list<int>  $subscriptionIds
     */
    public function __construct(public array $subscriptionIds) {}

    public function handle(UsageAggregator $aggregator): void
    {
        $subscriptions = Subscription::whereKey($this->subscriptionIds)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $aggregator->rollUp(
            $subscriptions->pluck('customer_id')->unique()->values()->all(),
            $subscriptions->min('current_period_start')->toDateString(),
            $subscriptions->max('current_period_end')->toDateString(),
        );

        $subscriptions->each(fn (Subscription $subscription) => GenerateInvoice::dispatch($subscription->id));
    }
}
