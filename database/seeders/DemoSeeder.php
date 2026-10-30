<?php

namespace Database\Seeders;

use App\Actions\ChangePlan;
use App\Actions\SubscribeCustomer;
use App\Enums\BillingCycle;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageEvent;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\UsageAggregator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Two merchants with a story for the dashboard. Data goes through the real
 * actions, rollup and invoicing, so nothing here bypasses the billing path.
 */
class DemoSeeder extends Seeder
{
    private const CUSTOMERS_PER_MERCHANT = 100;

    private const MERCHANTS = [
        'Acme Cloud' => 'demo-acme-key',
        'Nimbus APIs' => 'demo-nimbus-key',
    ];

    public function __construct(
        private SubscribeCustomer $subscribeCustomer,
        private ChangePlan $changePlan,
        private UsageAggregator $aggregator,
        private InvoiceGenerator $invoices,
    ) {}

    public function run(): void
    {
        mt_srand(42);

        foreach (self::MERCHANTS as $name => $apiKey) {
            $merchant = Merchant::create(['name' => $name, 'api_key_hash' => Merchant::hashApiKey($apiKey)]);

            $this->seedMerchant($merchant);

            $this->command?->info("{$name}: merchant ID {$merchant->id}, API key {$apiKey}");
        }
    }

    private function seedMerchant(Merchant $merchant): void
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $thisMonth = $today->startOfMonth();
        $lastMonth = $thisMonth->subMonthNoOverflow();

        [$starter, $growth] = $this->plans($merchant);
        $upgraded = [];

        for ($i = 1; $i <= self::CUSTOMERS_PER_MERCHANT; $i++) {
            $customer = Customer::create([
                'merchant_id' => $merchant->id,
                'external_id' => sprintf('cust-%03d', $i),
                'name' => fake()->company(),
                'email' => fake()->safeEmail(),
            ]);

            $profile = $this->profile($i);
            $startsOn = $profile === 'late_joiner' ? $thisMonth->addDays(min(9, $today->day - 1)) : $lastMonth;
            $subscription = $this->subscribeCustomer->handle($customer, $starter->id, $startsOn);

            $this->recordUsage($customer, $profile, $startsOn, $today, $thisMonth);

            if ($profile === 'upgrader') {
                $upgraded[] = $subscription;
            }
        }

        $this->aggregator->rollUp(
            Customer::where('merchant_id', $merchant->id)->pluck('id')->all(),
            $lastMonth->toDateString(),
            $today->toDateString(),
        );

        foreach ($this->invoices->due()->where('merchant_id', $merchant->id)->pluck('id') as $subscriptionId) {
            $this->invoices->generate($subscriptionId);
        }

        $this->upgrade($upgraded, $growth, $thisMonth);
    }

    /**
     * @return list<Plan>
     */
    private function plans(Merchant $merchant): array
    {
        return array_map(fn (array $plan) => Plan::create([
            'merchant_id' => $merchant->id,
            'name' => $plan[0],
            'billing_cycle' => BillingCycle::Monthly,
            'base_price_minor' => $plan[1],
            'included_units' => $plan[2],
            'overage_rate_micros' => $plan[3],
        ]), [
            ['Starter', 99900, 10000, 50000],
            ['Growth', 299900, 50000, 20000],
            ['Scale', 999900, 250000, 10000],
        ]);
    }

    private function profile(int $i): string
    {
        return match ($i % 10) {
            0 => 'churning',
            1 => 'heavy',
            2 => 'upgrader',
            3 => 'late_joiner',
            default => 'steady',
        };
    }

    private function recordUsage(Customer $customer, string $profile, CarbonImmutable $from, CarbonImmutable $today, CarbonImmutable $thisMonth): void
    {
        $rows = [];

        for ($date = $from; $date->lessThanOrEqualTo($today); $date = $date->addDay()) {
            $rows[] = [
                'merchant_id' => $customer->merchant_id,
                'customer_id' => $customer->id,
                'usage_date' => $date->toDateString(),
                'quantity' => $this->dailyQuantity($profile, $date->greaterThanOrEqualTo($thisMonth)),
                'idempotency_key' => "seed-{$customer->id}-{$date->toDateString()}",
            ];
        }

        UsageEvent::query()->insertOrIgnore($rows);
    }

    private function dailyQuantity(string $profile, bool $isThisMonth): int
    {
        return match ($profile) {
            'heavy', 'upgrader' => mt_rand(800, 1200),
            'churning' => $isThisMonth ? mt_rand(20, 40) : mt_rand(300, 500),
            default => mt_rand(200, 300),
        };
    }

    /**
     * A change must be dated in the future, so the clock is held mid-month to
     * show a split billing period whatever day the seeder runs.
     *
     * @param  list<Subscription>  $subscriptions
     */
    private function upgrade(array $subscriptions, Plan $plan, CarbonImmutable $thisMonth): void
    {
        $effectiveOn = $thisMonth->addDays(14);

        Carbon::setTestNow($effectiveOn->subDay());
        CarbonImmutable::setTestNow($effectiveOn->subDay());

        try {
            foreach ($subscriptions as $subscription) {
                $this->changePlan->handle($subscription, $plan->id, $effectiveOn);
            }
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }
}
