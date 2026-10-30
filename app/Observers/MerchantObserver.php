<?php

namespace App\Observers;

use App\Models\Merchant;
use App\Services\TenantDirectory;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class MerchantObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private TenantDirectory $directory) {}

    /**
     * A rotated key must stop working at once, so the previous hash is cleared too.
     */
    public function saved(Merchant $merchant): void
    {
        $this->directory->forgetMerchant($merchant->api_key_hash);

        if (isset($merchant->getPrevious()['api_key_hash'])) {
            $this->directory->forgetMerchant($merchant->getPrevious()['api_key_hash']);
        }
    }

    public function deleted(Merchant $merchant): void
    {
        $this->directory->forgetMerchant($merchant->api_key_hash);
    }
}
