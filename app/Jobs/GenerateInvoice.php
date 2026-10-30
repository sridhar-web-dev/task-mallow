<?php

namespace App\Jobs;

use App\Services\Billing\InvoiceGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateInvoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public int $subscriptionId) {}

    public function handle(InvoiceGenerator $generator): void
    {
        $generator->generate($this->subscriptionId);
    }
}
