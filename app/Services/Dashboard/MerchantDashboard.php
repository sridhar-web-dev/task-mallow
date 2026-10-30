<?php

namespace App\Services\Dashboard;

use App\Models\Customer;
use App\Models\DailyUsage;
use App\Models\Merchant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Every figure here reads daily_usage, never usage_events, so the page stays
 * fast as raw events grow. Figures can lag by the rollup and cache intervals.
 */
class MerchantDashboard
{
    private const TOP_CUSTOMERS = 5;

    private const CHURN_LIST_LIMIT = 50;

    public function __construct(private OverageProjector $projector) {}

    /**
     * @return array<string, mixed>
     */
    public function forMerchant(Merchant $merchant): array
    {
        return Cache::remember(
            "merchant:{$merchant->id}:dashboard",
            config('billing.cache.dashboard_ttl_seconds'),
            fn () => $this->build($merchant, CarbonImmutable::now('UTC')->startOfDay()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function build(Merchant $merchant, CarbonImmutable $today): array
    {
        $top = $this->topCustomers($merchant->id, $today);
        $churn = $this->churnCandidates($merchant->id, $today);
        $names = $this->customers($top->pluck('customer_id')->merge($churn['rows']->pluck('customer_id')));

        return [
            'merchant' => ['id' => $merchant->id, 'name' => $merchant->name, 'currency' => $merchant->currency],
            'generated_at' => now('UTC')->toIso8601String(),
            'top_customers' => [
                'from' => $today->startOfMonth()->toDateString(),
                'to' => $today->toDateString(),
                'customers' => $top->map(fn (object $row) => [
                    'customer_id' => $names[$row->customer_id]->external_id,
                    'name' => $names[$row->customer_id]->name,
                    'units' => (int) $row->total_units,
                ])->all(),
            ],
            'projected_overage' => [
                'amount_minor' => $this->projector->project($merchant->id, $today),
                'currency' => $merchant->currency,
            ],
            'churn_risk' => [
                ...$churn['window'],
                'total' => $churn['rows']->count(),
                'customers' => $churn['rows']->take(self::CHURN_LIST_LIMIT)->map(fn (object $row) => [
                    'customer_id' => $names[$row->customer_id]->external_id,
                    'name' => $names[$row->customer_id]->name,
                    'current_units' => (int) $row->current_units,
                    'previous_units' => (int) $row->previous_units,
                    'drop_percent' => round(100 * ($row->previous_units - $row->current_units) / $row->previous_units, 1),
                ])->values()->all(),
            ],
        ];
    }

    private function topCustomers(int $merchantId, CarbonImmutable $today): Collection
    {
        return DailyUsage::query()
            ->selectRaw('customer_id, SUM(units) as total_units')
            ->where('merchant_id', $merchantId)
            ->whereBetween('usage_date', [$today->startOfMonth()->toDateString(), $today->toDateString()])
            ->groupBy('customer_id')
            ->orderByDesc('total_units')
            ->orderBy('customer_id')
            ->limit(self::TOP_CUSTOMERS)
            ->toBase()
            ->get();
    }

    /**
     * Compares this month to the same days of last month, because a part month
     * against a whole one would flag nearly everyone early in the month. Today is
     * left out as it is still accumulating, which would read as a drop.
     *
     * @return array{window: array<string, string|null>, rows: Collection<int, object>}
     */
    private function churnCandidates(int $merchantId, CarbonImmutable $today): array
    {
        $currentFrom = $today->startOfMonth();
        $currentTo = $today->subDay();

        if ($currentTo->lessThan($currentFrom)) {
            return [
                'window' => ['current_from' => null, 'current_to' => null, 'previous_from' => null, 'previous_to' => null],
                'rows' => collect(),
            ];
        }

        $previousFrom = $currentFrom->subMonthNoOverflow();
        $previousTo = $currentTo->subMonthNoOverflow();

        $rows = DailyUsage::query()
            ->selectRaw(
                'customer_id,
                SUM(CASE WHEN usage_date >= ? THEN units ELSE 0 END) as current_units,
                SUM(CASE WHEN usage_date <= ? THEN units ELSE 0 END) as previous_units',
                [$currentFrom->toDateString(), $previousTo->toDateString()],
            )
            ->where('merchant_id', $merchantId)
            ->where('usage_date', '>=', $previousFrom->toDateString())
            ->where('usage_date', '<=', $currentTo->toDateString())
            ->groupBy('customer_id')
            ->havingRaw('previous_units > 0 AND current_units * 2 < previous_units')
            ->toBase()
            ->get()
            ->sortBy([
                fn (object $a, object $b) => ($b->previous_units - $b->current_units) <=> ($a->previous_units - $a->current_units),
                fn (object $a, object $b) => $a->customer_id <=> $b->customer_id,
            ])
            ->values();

        return [
            'window' => [
                'current_from' => $currentFrom->toDateString(),
                'current_to' => $currentTo->toDateString(),
                'previous_from' => $previousFrom->toDateString(),
                'previous_to' => $previousTo->toDateString(),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return Collection<int, Customer>
     */
    private function customers(Collection $ids): Collection
    {
        return Customer::whereKey($ids->unique()->values())->get(['id', 'external_id', 'name'])->keyBy('id');
    }
}
