<?php

namespace App\Observers;

use App\Models\Customer;
use App\Services\TenantDirectory;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class CustomerObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private TenantDirectory $directory) {}

    public function saved(Customer $customer): void
    {
        $this->directory->forgetCustomer($customer->merchant_id, $customer->external_id);

        if (isset($customer->getPrevious()['external_id'])) {
            $this->directory->forgetCustomer($customer->merchant_id, $customer->getPrevious()['external_id']);
        }
    }

    public function deleted(Customer $customer): void
    {
        $this->directory->forgetCustomer($customer->merchant_id, $customer->external_id);
    }
}
