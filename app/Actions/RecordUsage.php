<?php

namespace App\Actions;

use App\DTOs\RecordUsageData;
use App\Models\UsageEvent;
use App\Services\TenantDirectory;
use Illuminate\Validation\ValidationException;

class RecordUsage
{
    public function __construct(private TenantDirectory $directory) {}

    /**
     * Returns false when this idempotency key was already recorded.
     *
     * The unique index decides, so concurrent retries cannot both insert, and
     * no read is needed first to check for a duplicate.
     */
    public function handle(RecordUsageData $data): bool
    {
        $customerId = $this->directory->customerId($data->merchantId, $data->customerExternalId);

        if ($customerId === null) {
            throw ValidationException::withMessages(['customer_id' => 'Unknown customer.']);
        }

        $inserted = UsageEvent::query()->insertOrIgnore([
            'merchant_id' => $data->merchantId,
            'customer_id' => $customerId,
            'usage_date' => $data->usageDate,
            'quantity' => $data->quantity,
            'idempotency_key' => $data->idempotencyKey,
        ]);

        return $inserted === 1;
    }
}
