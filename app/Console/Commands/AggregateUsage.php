<?php

namespace App\Console\Commands;

use App\Jobs\AggregateUsageChunk;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class AggregateUsage extends Command
{
    protected $signature = 'billing:aggregate {--days= : Days to look back; defaults to the late window}';

    protected $description = 'Queue chunked rollups of usage events into daily usage';

    public function handle(): int
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $days = $this->option('days') ?? config('billing.usage.late_window_days');

        $from = $today->subDays((int) $days)->toDateString();
        $to = $today->toDateString();
        $chunks = 0;

        Customer::query()->select('id')->chunkById(
            config('billing.chunk_size'),
            function (Collection $customers) use ($from, $to, &$chunks) {
                AggregateUsageChunk::dispatch($customers->modelKeys(), $from, $to);
                $chunks++;
            },
        );

        $this->info("Queued {$chunks} rollup chunks for {$from} to {$to}.");

        return self::SUCCESS;
    }
}
