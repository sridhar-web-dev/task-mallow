<?php

use App\Actions\CancelSubscription;
use App\Actions\ChangePlan;
use App\Actions\SubscribeCustomer;
use App\Models\Customer;
use App\Models\DailyUsage;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create(['name' => 'Acme', 'api_key_hash' => Merchant::hashApiKey('test-key')]);
    // ₹999 a month, 10,000 units included, ₹0.05 per extra unit.
    $this->starter = Plan::factory()->create(['merchant_id' => $this->merchant->id]);
    // ₹2,999 a month, 50,000 units included, ₹0.02 per extra unit.
    $this->pro = Plan::factory()->create([
        'merchant_id' => $this->merchant->id,
        'base_price_minor' => 299900,
        'included_units' => 50000,
        'overage_rate_micros' => 20000,
    ]);
});

function dashboard(string $apiKey = 'test-key', ?int $merchantId = null): TestResponse
{
    $merchantId ??= test()->merchant->id;

    return test()->getJson("/api/merchants/{$merchantId}/dashboard", ['X-Api-Key' => $apiKey]);
}

function tenantCustomer(string $name = 'Customer'): Customer
{
    return Customer::factory()->create(['merchant_id' => test()->merchant->id, 'name' => $name]);
}

function dailyUnits(Customer $customer, string $date, int $units): void
{
    DailyUsage::factory()->create(['customer_id' => $customer->id, 'usage_date' => $date, 'units' => $units]);
}

function subscribedFrom(string $date, ?Plan $plan = null): Subscription
{
    return app(SubscribeCustomer::class)->handle(tenantCustomer(), ($plan ?? test()->starter)->id, CarbonImmutable::parse($date));
}

function projectedOverage(): int
{
    return dashboard()->assertOk()->json('projected_overage.amount_minor');
}

describe('top customers', function () {
    beforeEach(fn () => $this->travelTo('2026-11-10 12:00:00'));

    it('returns the five biggest customers this month in order', function () {
        foreach ([100, 600, 300, 500, 200, 400] as $units) {
            dailyUnits(tenantCustomer("Customer {$units}"), '2026-11-05', $units);
        }

        $customers = dashboard()->assertOk()->json('top_customers.customers');

        expect(array_column($customers, 'units'))->toBe([600, 500, 400, 300, 200])
            ->and($customers[0]['name'])->toBe('Customer 600');
    });

    it('adds up a customer across days', function () {
        $customer = tenantCustomer();
        dailyUnits($customer, '2026-11-01', 10);
        dailyUnits($customer, '2026-11-10', 5);

        expect(dashboard()->json('top_customers.customers.0.units'))->toBe(15);
    });

    it('ignores last month', function () {
        dailyUnits(tenantCustomer(), '2026-10-31', 999);

        expect(dashboard()->json('top_customers.customers'))->toBeEmpty();
    });

    it('breaks ties by the older customer', function () {
        $first = tenantCustomer('First');
        $second = tenantCustomer('Second');
        dailyUnits($second, '2026-11-05', 50);
        dailyUnits($first, '2026-11-05', 50);

        expect(dashboard()->json('top_customers.customers.0.name'))->toBe('First');
    });
});

describe('projected overage', function () {
    it('is zero with no usage', function () {
        $this->travelTo('2026-10-11 12:00:00');
        subscribedFrom('2026-10-01');

        expect(projectedOverage())->toBe(0);
    });

    it('is zero when the forecast stays inside the allowance', function () {
        $this->travelTo('2026-10-11 12:00:00');
        $subscription = subscribedFrom('2026-10-01');
        dailyUnits($subscription->customer, '2026-10-05', 3000);

        expect(projectedOverage())->toBe(0);
    });

    it('extends the daily average to the end of the cycle', function () {
        $this->travelTo('2026-10-11 12:00:00');
        $subscription = subscribedFrom('2026-10-01');
        dailyUnits($subscription->customer, '2026-10-05', 11000);

        // 1,000 a day for 31 days is 31,000: 21,000 over at 5 paise.
        expect(projectedOverage())->toBe(105000);
    });

    it('prorates the allowance for a mid-cycle start', function () {
        $this->travelTo('2026-10-25 12:00:00');
        $subscription = subscribedFrom('2026-10-21');
        dailyUnits($subscription->customer, '2026-10-22', 5000);

        // 5 of 11 days gone, forecast 11,000. 11/31 of the allowance is 3,548.
        expect(projectedOverage())->toBe(7452 * 5);
    });

    it('uses actual usage for a finished segment', function () {
        $this->travelTo('2026-10-05 12:00:00');
        $subscription = subscribedFrom('2026-10-01');
        app(ChangePlan::class)->handle($subscription, $this->pro->id, CarbonImmutable::parse('2026-10-16'));
        dailyUnits($subscription->customer, '2026-10-05', 6000);
        dailyUnits($subscription->customer, '2026-10-17', 30000);

        $this->travelTo('2026-10-20 12:00:00');

        // Starter actual: 6,000, 1,162 over at 5 paise = 5810.
        // Pro forecast: 30,000 + 11 days at 1,800 = 49,800, 23,994 over at 2 paise = 47988.
        expect(projectedOverage())->toBe(5810 + 47988);
    });

    it('forecasts a scheduled segment that has not started', function () {
        $this->travelTo('2026-10-11 12:00:00');
        $subscription = subscribedFrom('2026-10-01');
        app(ChangePlan::class)->handle($subscription, $this->pro->id, CarbonImmutable::parse('2026-10-21'));
        dailyUnits($subscription->customer, '2026-10-05', 11000);

        // Starter: 20,000 against 6,451 included. Pro: 11,000 against 17,741 included.
        expect(projectedOverage())->toBe(13549 * 5);
    });

    it('adds up every active subscription', function () {
        $this->travelTo('2026-10-11 12:00:00');
        dailyUnits(subscribedFrom('2026-10-01')->customer, '2026-10-05', 11000);
        dailyUnits(subscribedFrom('2026-10-01')->customer, '2026-10-05', 11000);

        expect(projectedOverage())->toBe(210000);
    });

    it('agrees with the real invoice once the period is over', function () {
        $this->travelTo('2026-10-11 12:00:00');
        $subscription = subscribedFrom('2026-10-01');
        dailyUnits($subscription->customer, '2026-10-05', 12000);

        $this->travelTo('2026-11-02 12:00:00');

        expect(projectedOverage())->toBe(2000 * 5);
    });

    it('leaves out a fully canceled subscription', function () {
        $this->travelTo('2026-10-11 12:00:00');
        $subscription = subscribedFrom('2026-10-01');
        dailyUnits($subscription->customer, '2026-10-05', 50000);
        app(CancelSubscription::class)->handle($subscription, CarbonImmutable::parse('2026-10-11'));
        $subscription->update(['status' => 'canceled']);

        expect(projectedOverage())->toBe(0);
    });
});

describe('churn risk', function () {
    beforeEach(fn () => $this->travelTo('2026-11-10 12:00:00'));

    it('flags a drop over 50 percent on matching days', function () {
        $customer = tenantCustomer('Fading');
        dailyUnits($customer, '2026-10-05', 1000);
        dailyUnits($customer, '2026-11-05', 499);

        $risk = dashboard()->json('churn_risk');

        expect($risk['total'])->toBe(1)
            ->and($risk['customers'][0])->toMatchArray([
                'name' => 'Fading',
                'previous_units' => 1000,
                'current_units' => 499,
                'drop_percent' => 50.1,
            ]);
    });

    it('does not flag a drop of exactly 50 percent', function () {
        $customer = tenantCustomer();
        dailyUnits($customer, '2026-10-05', 1000);
        dailyUnits($customer, '2026-11-05', 500);

        expect(dashboard()->json('churn_risk.total'))->toBe(0);
    });

    it('flags a customer with no usage this month', function () {
        dailyUnits(tenantCustomer(), '2026-10-05', 1000);

        expect(dashboard()->json('churn_risk.customers.0.drop_percent'))->toEqual(100);
    });

    it('does not flag a new customer', function () {
        dailyUnits(tenantCustomer(), '2026-11-05', 10);

        expect(dashboard()->json('churn_risk.total'))->toBe(0);
    });

    it('does not count last month past the matching day', function () {
        $customer = tenantCustomer();
        dailyUnits($customer, '2026-10-05', 100);
        dailyUnits($customer, '2026-10-25', 100000);
        dailyUnits($customer, '2026-11-05', 100);

        expect(dashboard()->json('churn_risk.total'))->toBe(0);
    });

    it('leaves today out of the comparison', function () {
        $customer = tenantCustomer();
        dailyUnits($customer, '2026-10-05', 100);
        dailyUnits($customer, '2026-10-10', 100000);
        dailyUnits($customer, '2026-11-05', 100);

        $risk = dashboard()->json('churn_risk');

        expect($risk['current_to'])->toBe('2026-11-09')
            ->and($risk['previous_to'])->toBe('2026-10-09')
            ->and($risk['total'])->toBe(0);
    });

    it('sorts by the biggest drop', function () {
        $small = tenantCustomer('Small');
        $large = tenantCustomer('Large');
        dailyUnits($small, '2026-10-05', 100);
        dailyUnits($large, '2026-10-05', 9000);

        expect(dashboard()->json('churn_risk.customers.0.name'))->toBe('Large');
    });

    it('has nothing to compare on the first of the month', function () {
        $this->travelTo('2026-11-01 12:00:00');
        dailyUnits(tenantCustomer(), '2026-10-05', 1000);

        $risk = dashboard()->assertOk()->json('churn_risk');

        expect($risk['total'])->toBe(0)
            ->and($risk['current_from'])->toBeNull();
    });

    it('clamps to the end of a shorter previous month', function () {
        $this->travelTo('2026-03-31 12:00:00');

        expect(dashboard()->json('churn_risk.previous_to'))->toBe('2026-02-28');
    });
});

describe('access', function () {
    beforeEach(fn () => $this->travelTo('2026-11-10 12:00:00'));

    it('requires an api key', function () {
        $this->getJson("/api/merchants/{$this->merchant->id}/dashboard")->assertUnauthorized();
    });

    it('returns 404 for another merchant', function () {
        $other = Merchant::factory()->create(['api_key_hash' => Merchant::hashApiKey('other-key')]);

        dashboard('other-key')->assertNotFound();
        dashboard('other-key', $other->id)->assertOk();
    });

    it('shows no data from another merchant', function () {
        $otherCustomer = Customer::factory()->create();
        dailyUnits($otherCustomer, '2026-11-05', 500);
        dailyUnits($otherCustomer, '2026-10-05', 5000);

        $response = dashboard()->assertOk();

        expect($response->json('top_customers.customers'))->toBeEmpty()
            ->and($response->json('churn_risk.total'))->toBe(0);
    });
});

describe('caching', function () {
    beforeEach(fn () => $this->travelTo('2026-11-10 12:00:00'));

    it('runs no dashboard queries on a repeat request', function () {
        dashboard()->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();

        dashboard()->assertOk();

        expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query) => str_contains($query, 'daily_usage')))->toBeEmpty();
    });

    it('refreshes once the ttl expires', function () {
        dashboard()->assertOk();
        dailyUnits(tenantCustomer(), '2026-11-05', 10);

        expect(dashboard()->json('top_customers.customers'))->toBeEmpty();

        $this->travel(config('billing.cache.dashboard_ttl_seconds') + 1)->seconds();

        expect(dashboard()->json('top_customers.customers'))->toHaveCount(1);
    });

    it('keeps merchants separate in the cache', function () {
        $other = Merchant::factory()->create(['name' => 'Other', 'api_key_hash' => Merchant::hashApiKey('other-key')]);

        dashboard()->assertOk()->assertJsonPath('merchant.name', 'Acme');
        dashboard('other-key', $other->id)->assertOk()->assertJsonPath('merchant.name', 'Other');
    });
});

it('serves the dashboard page', function () {
    $this->get('/dashboard')->assertOk()->assertSee('Merchant Dashboard');
});
