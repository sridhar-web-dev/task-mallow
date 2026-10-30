<?php

namespace App\Jobs;

use App\Services\Billing\UsageAggregator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AggregateUsageChunk implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  list<int>  $customerIds
     */
    public function __construct(
        public array $customerIds,
        public string $from,
        public string $to,
    ) {}

    public function handle(UsageAggregator $aggregator): void
    {
        $aggregator->rollUp($this->customerIds, $this->from, $this->to);
    }
}
