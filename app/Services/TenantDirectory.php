<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Merchant;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cached id lookups for POST /usage, which would otherwise read twice per write.
 *
 * Only hits are cached. A new customer or merchant works immediately, and an
 * unknown one is never remembered as missing.
 */
class TenantDirectory
{
    public function merchantIdForApiKey(string $apiKey): ?int
    {
        $hash = Merchant::hashApiKey($apiKey);

        return $this->rememberHit(
            self::merchantKey($hash),
            fn () => Merchant::where('api_key_hash', $hash)->value('id'),
        );
    }

    public function customerId(int $merchantId, string $externalId): ?int
    {
        return $this->rememberHit(
            self::customerKey($merchantId, $externalId),
            fn () => Customer::where('merchant_id', $merchantId)->where('external_id', $externalId)->value('id'),
        );
    }

    public function forgetMerchant(string $apiKeyHash): void
    {
        Cache::forget(self::merchantKey($apiKeyHash));
    }

    public function forgetCustomer(int $merchantId, string $externalId): void
    {
        Cache::forget(self::customerKey($merchantId, $externalId));
    }

    private function rememberHit(string $key, Closure $lookup): ?int
    {
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        $id = $lookup();

        if ($id !== null) {
            Cache::put($key, $id, config('billing.cache.ttl_seconds'));
        }

        return $id;
    }

    private static function merchantKey(string $apiKeyHash): string
    {
        return "merchant-api-key:{$apiKeyHash}";
    }

    private static function customerKey(int $merchantId, string $externalId): string
    {
        return "merchant:{$merchantId}:customer:".hash('sha256', $externalId);
    }
}
