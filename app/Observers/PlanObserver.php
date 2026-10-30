<?php

namespace App\Observers;

use App\Models\Plan;
use App\Services\PlanCatalog;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Runs after commit, so a reader cannot reload the old row into the cache
 * between this invalidation and the transaction landing.
 */
class PlanObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private PlanCatalog $catalog) {}

    public function saved(Plan $plan): void
    {
        $this->catalog->forget($plan->id);
    }

    public function deleted(Plan $plan): void
    {
        $this->catalog->forget($plan->id);
    }
}
