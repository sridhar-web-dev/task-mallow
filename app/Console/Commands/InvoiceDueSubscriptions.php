<?php

namespace App\Console\Commands;

use App\Jobs\CloseBillingChunk;
use App\Services\Billing\InvoiceGenerator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class InvoiceDueSubscriptions extends Command
{
    protected $signature = 'billing:invoice';

    protected $description = 'Queue chunked invoicing for subscriptions whose period has closed';

    public function handle(InvoiceGenerator $generator): int
    {
        $chunks = 0;

        $generator->due()->select('id')->chunkById(
            config('billing.chunk_size'),
            function (Collection $subscriptions) use (&$chunks) {
                CloseBillingChunk::dispatch($subscriptions->modelKeys());
                $chunks++;
            },
        );

        $this->info("Queued {$chunks} billing chunks.");

        return self::SUCCESS;
    }
}
