<?php

namespace App\Console\Commands;

use App\Actions\SubscribeCustomer;
use App\Enums\BillingCycle;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoadTestData extends Command
{
    private const MERCHANT_NAME = 'Load Test';

    // Stays under MariaDB's default 1 MB max_allowed_packet.
    private const BATCH_SIZE = 2000;

    private const BYTES_PER_GB = 1024 ** 3;

    protected $signature = 'billing:load-test
        {--rows=5000000 : Usage events to insert}
        {--customers=2000 : Customers to spread them across}
        {--days=90 : Days of history to spread them across}
        {--min-free-gb=1 : Stop if the database disk falls below this}';

    protected $description = 'Bulk-insert usage events to measure the schema at volume (never in production)';

    public function handle(SubscribeCustomer $subscribeCustomer): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('This command never runs in production.');

            return self::FAILURE;
        }

        $merchant = Merchant::firstOrCreate(
            ['name' => self::MERCHANT_NAME],
            ['api_key_hash' => Merchant::hashApiKey('load-test-key')],
        );

        $customerIds = $this->customerIds($merchant, $subscribeCustomer);
        $rows = (int) $this->option('rows');
        $dataDirectory = DB::selectOne('select @@datadir as path')->path;

        $started = microtime(true);
        $written = 0;
        $batch = [];

        foreach ($this->events($rows, $merchant->id, $customerIds, $this->days()) as $event) {
            $batch[] = $event;

            if (count($batch) < self::BATCH_SIZE) {
                continue;
            }

            DB::table('usage_events')->insert($batch);
            $written += count($batch);
            $batch = [];

            if ($written % 100_000 === 0) {
                $freeGb = disk_free_space($dataDirectory) / self::BYTES_PER_GB;
                $this->line(sprintf('%d rows, %.0f rows/s, %.2f GB free', $written, $written / (microtime(true) - $started), $freeGb));

                if ($freeGb < (float) $this->option('min-free-gb')) {
                    $this->error('Stopping: the database disk is nearly full.');

                    return self::FAILURE;
                }
            }
        }

        if ($batch !== []) {
            DB::table('usage_events')->insert($batch);
            $written += count($batch);
        }

        $this->info(sprintf('Inserted %d rows in %.1fs.', $written, microtime(true) - $started));

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function customerIds(Merchant $merchant, SubscribeCustomer $subscribeCustomer): array
    {
        $existing = Customer::where('merchant_id', $merchant->id)->pluck('id')->all();

        if ($existing !== []) {
            return $existing;
        }

        $plan = Plan::create([
            'merchant_id' => $merchant->id,
            'name' => 'Load Test Plan',
            'billing_cycle' => BillingCycle::Monthly,
            'base_price_minor' => 99900,
            'included_units' => 10000,
            'overage_rate_micros' => 50000,
        ]);

        $startsOn = CarbonImmutable::now('UTC')->startOfMonth();

        return collect(range(1, (int) $this->option('customers')))
            ->map(function (int $i) use ($merchant, $plan, $startsOn, $subscribeCustomer) {
                $customer = Customer::create([
                    'merchant_id' => $merchant->id,
                    'external_id' => "load-{$i}",
                    'name' => "Load Customer {$i}",
                ]);

                $subscribeCustomer->handle($customer, $plan->id, $startsOn);

                return $customer->id;
            })
            ->all();
    }

    /**
     * @return list<string>
     */
    private function days(): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();

        return array_map(
            fn (int $offset) => $today->subDays($offset)->toDateString(),
            range((int) $this->option('days') - 1, 0),
        );
    }

    /**
     * Rows come in index order, day by day and customer by customer, so loading
     * stays fast on a small buffer pool. This measures reads at volume; it does
     * not represent live ingest, which arrives in random customer order.
     *
     * @param  list<int>  $customerIds
     * @param  list<string>  $days
     */
    private function events(int $rows, int $merchantId, array $customerIds, array $days): Generator
    {
        $perCustomerDay = (int) ceil($rows / (count($days) * count($customerIds)));
        $emitted = 0;

        foreach ($days as $day) {
            foreach ($customerIds as $customerId) {
                for ($i = 0; $i < $perCustomerDay; $i++) {
                    if ($emitted++ === $rows) {
                        return;
                    }

                    yield [
                        'merchant_id' => $merchantId,
                        'customer_id' => $customerId,
                        'usage_date' => $day,
                        'quantity' => mt_rand(1, 100),
                        'idempotency_key' => (string) Str::uuid(),
                    ];
                }
            }
        }
    }
}
