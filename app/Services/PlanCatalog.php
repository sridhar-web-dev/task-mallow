<?php

namespace App\Services;

use App\DTOs\PlanPricing;
use App\Models\Plan;
use Illuminate\Support\Facades\Cache;

class PlanCatalog
{
    public function find(int $planId): ?PlanPricing
    {
        $key = self::planKey($planId);

        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        $plan = Plan::find($planId);

        if ($plan === null) {
            return null;
        }

        $pricing = PlanPricing::fromModel($plan);
        Cache::put($key, $pricing, config('billing.cache.ttl_seconds'));

        return $pricing;
    }

    public function forget(int $planId): void
    {
        Cache::forget(self::planKey($planId));
    }

    private static function planKey(int $planId): string
    {
        return "plan:{$planId}";
    }
}
