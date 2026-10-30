<?php

namespace App\Services\Billing;

use App\Models\DailyUsage;
use App\Models\UsageEvent;

class UsageAggregator
{
    private const UPSERT_BATCH_SIZE = 1000;

    /**
     * Recomputes each day from raw events and overwrites the rollup, so running
     * this twice, or after a late event, gives the same totals as running it once.
     *
     * @param  list<int>  $customerIds
     */
    public function rollUp(array $customerIds, string $from, string $to): void
    {
        $rows = UsageEvent::query()
            ->select('merchant_id', 'customer_id', 'usage_date')
            ->selectRaw('SUM(quantity) as units')
            ->whereIn('customer_id', $customerIds)
            ->whereBetween('usage_date', [$from, $to])
            ->groupBy('merchant_id', 'customer_id', 'usage_date')
            ->toBase()
            ->get()
            ->map(fn (object $row) => [
                'merchant_id' => $row->merchant_id,
                'customer_id' => $row->customer_id,
                'usage_date' => $row->usage_date,
                'units' => (int) $row->units,
            ])
            ->all();

        foreach (array_chunk($rows, self::UPSERT_BATCH_SIZE) as $batch) {
            DailyUsage::upsert($batch, ['customer_id', 'usage_date'], ['units']);
        }
    }
}
