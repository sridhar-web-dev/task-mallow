<?php

namespace App\Http\Requests;

use App\DTOs\RecordUsageData;
use App\Http\Middleware\AuthenticateMerchant;
use Illuminate\Foundation\Http\FormRequest;

class RecordUsageRequest extends FormRequest
{
    /**
     * The merchant is authenticated by middleware before this request runs.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $today = now('UTC')->startOfDay();
        $earliest = $today->copy()->subDays(config('billing.usage.late_window_days'));

        return [
            'customer_id' => ['required', 'string', 'max:64'],
            'usage_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$earliest->toDateString(),
                'before_or_equal:'.$today->toDateString(),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.config('billing.usage.max_quantity')],
            'idempotency_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }

    public function toData(): RecordUsageData
    {
        return new RecordUsageData(
            merchantId: $this->attributes->get(AuthenticateMerchant::REQUEST_ATTRIBUTE),
            customerExternalId: $this->string('customer_id')->toString(),
            usageDate: $this->string('usage_date')->toString(),
            quantity: $this->integer('quantity'),
            idempotencyKey: $this->string('idempotency_key')->toString(),
        );
    }
}
