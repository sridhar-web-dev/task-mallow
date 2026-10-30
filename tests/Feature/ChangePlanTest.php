<?php

use App\Actions\CancelSubscription;
use App\Actions\SubscribeCustomer;
use App\Enums\BillingCycle;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-05 12:00:00');

    $this->merchant = Merchant::factory()->create(['api_key_hash' => Merchant::hashApiKey('test-key')]);
    // ₹999 a month, 10,000 units included, ₹0.05 per extra unit.
    $this->starter = Plan::factory()->create(['merchant_id' => $this->merchant->id, 'name' => 'Starter']);
    // ₹2,999 a month, 50,000 units included, ₹0.02 per extra unit.
    $this->pro = Plan::factory()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Pro',
        'base_price_minor' => 299900,
        'included_units' => 50000,
        'overage_rate_micros' => 20000,
    ]);
});

function onPlan(Plan $plan, string $startsOn = '2026-10-01'): Subscription
{
    $customer = Customer::factory()->create(['merchant_id' => $plan->merchant_id]);

    return app(SubscribeCustomer::class)->handle($customer, $plan->id, CarbonImmutable::parse($startsOn));
}

function changeTo(Subscription $subscription, Plan $plan, ?string $effectiveOn = null, string $apiKey = 'test-key'): TestResponse
{
    return test()->postJson(
        "/api/subscriptions/{$subscription->id}/change-plan",
        array_filter(['plan_id' => $plan->id, 'effective_on' => $effectiveOn]),
        ['X-Api-Key' => $apiKey],
    );
}

function useUnits(Subscription $subscription, string $date, int $quantity): void
{
    UsageEvent::factory()->create([
        'customer_id' => $subscription->customer_id,
        'usage_date' => $date,
        'quantity' => $quantity,
    ]);
}

function invoiceOn(string $date): Invoice
{
    test()->travelTo($date.' 00:30:00');
    test()->artisan('billing:invoice')->assertSuccessful();

    return Invoice::orderByDesc('period_start')->firstOrFail();
}

describe('changing plan', function () {
    it('splits the open segment at the effective date', function () {
        $subscription = onPlan($this->starter);

        changeTo($subscription, $this->pro, '2026-10-16')
            ->assertOk()
            ->assertExactJson([
                'subscription_id' => $subscription->id,
                'plan_id' => $this->pro->id,
                'effective_on' => '2026-10-16',
            ]);

        [$old, $new] = $subscription->segments()->orderBy('starts_on')->get()->all();

        expect($old->plan_id)->toBe($this->starter->id)
            ->and($old->ends_on->toDateString())->toBe('2026-10-16')
            ->and($new->plan_id)->toBe($this->pro->id)
            ->and($new->starts_on->toDateString())->toBe('2026-10-16')
            ->and($new->ends_on)->toBeNull()
            ->and($subscription->fresh()->plan_id)->toBe($this->pro->id);
    });

    it('defaults to tomorrow', function () {
        $subscription = onPlan($this->starter);

        changeTo($subscription, $this->pro)->assertOk()->assertJsonPath('effective_on', '2026-10-06');
    });

    it('snapshots the new plan at its current price', function () {
        $subscription = onPlan($this->starter);
        $this->pro->update(['base_price_minor' => 349900]);

        changeTo($subscription, $this->pro, '2026-10-16')->assertOk();

        expect($subscription->segments()->whereNull('ends_on')->sole()->base_price_minor)->toBe(349900);
    });
});

describe('billing a change', function () {
    it('bills an upgrade at both plans', function () {
        $subscription = onPlan($this->starter);
        changeTo($subscription, $this->pro, '2026-10-16')->assertOk();
        useUnits($subscription, '2026-10-15', 6000);
        useUnits($subscription, '2026-10-16', 30000);

        $invoice = invoiceOn('2026-11-04');

        // Starter 15/31: 48339 base, 1162 over at 5 paise = 5810.
        // Pro 16/31: 154787 base, 4194 over at 2 paise = 8388.
        expect($invoice->base_amount_minor)->toBe(203126)
            ->and($invoice->overage_amount_minor)->toBe(14198)
            ->and($invoice->total_minor)->toBe(217324)
            ->and($invoice->lines)->toHaveCount(4);
    });

    it('bills a downgrade at both plans', function () {
        $subscription = onPlan($this->pro);
        changeTo($subscription, $this->starter, '2026-10-16')->assertOk();
        useUnits($subscription, '2026-10-20', 6000);

        // Pro's unused allowance does not cover Starter's 839 units over.
        expect(invoiceOn('2026-11-04')->total_minor)->toBe(200869);
    });

    it('bills a day of usage at the plan in force that day', function () {
        $subscription = onPlan($this->starter);
        changeTo($subscription, $this->pro, '2026-10-16')->assertOk();
        useUnits($subscription, '2026-10-15', 4839);
        useUnits($subscription, '2026-10-16', 25807);

        $overage = invoiceOn('2026-11-04')->lines->where('type.value', 'overage')->values();

        expect($overage)->toHaveCount(2)
            ->and($overage[0]->amount_minor)->toBe(5)
            ->and($overage[1]->amount_minor)->toBe(2);
    });

    it('bills two changes in one cycle', function () {
        $subscription = onPlan($this->starter);
        changeTo($subscription, $this->pro, '2026-10-11')->assertOk();

        $this->travelTo('2026-10-15 12:00:00');
        changeTo($subscription, $this->starter, '2026-10-21')->assertOk();

        expect(invoiceOn('2026-11-04')->base_amount_minor)->toBe(32226 + 96742 + 35448);
    });

    it('bills a change on the last day of the period', function () {
        $subscription = onPlan($this->starter);
        changeTo($subscription, $this->pro, '2026-10-31')->assertOk();

        expect(invoiceOn('2026-11-04')->base_amount_minor)->toBe(96677 + 9674);
    });

    it('combines a mid-cycle start with a change', function () {
        $subscription = onPlan($this->starter, '2026-10-02');
        changeTo($subscription, $this->pro, '2026-10-25')->assertOk();

        // 23/31 of Starter and 7/31 of Pro.
        expect(invoiceOn('2026-11-04')->base_amount_minor)->toBe(74119 + 67719);
    });

    it('renews on the new plan', function () {
        $subscription = onPlan($this->starter);
        changeTo($subscription, $this->pro, '2026-10-16')->assertOk();

        invoiceOn('2026-11-04');

        $open = $subscription->segments()->whereNull('ends_on')->sole();

        expect($open->plan_id)->toBe($this->pro->id)
            ->and($open->base_price_minor)->toBe(299900)
            ->and($open->starts_on->toDateString())->toBe('2026-11-01');
    });

    it('bills a full next period on the new plan', function () {
        $subscription = onPlan($this->starter);
        changeTo($subscription, $this->pro, '2026-10-16')->assertOk();

        invoiceOn('2026-11-04');
        $this->artisan('billing:invoice');

        expect(invoiceOn('2026-12-04')->total_minor)->toBe(299900);
    });
});

describe('rejections', function () {
    it('rejects a date that is not after today', function (string $date) {
        changeTo(onPlan($this->starter), $this->pro, $date)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('effective_on');
    })->with(['today' => '2026-10-05', 'the past' => '2026-10-02']);

    it('rejects a date past the current period', function (string $date) {
        changeTo(onPlan($this->starter), $this->pro, $date)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('effective_on');
    })->with(['the period end' => '2026-11-01', 'next month' => '2026-11-15']);

    it('rejects a malformed date', function () {
        changeTo(onPlan($this->starter), $this->pro, '16-10-2026')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('effective_on');
    });

    it('rejects a missing plan', function () {
        $this->postJson('/api/subscriptions/'.onPlan($this->starter)->id.'/change-plan', [], ['X-Api-Key' => 'test-key'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id');
    });

    it('rejects the current plan', function () {
        changeTo(onPlan($this->starter), $this->starter, '2026-10-16')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id');
    });

    it('rejects an inactive plan', function () {
        $this->pro->update(['is_active' => false]);

        changeTo(onPlan($this->starter), $this->pro, '2026-10-16')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id');
    });

    it('rejects switching between monthly and yearly', function () {
        $yearly = Plan::factory()->create(['merchant_id' => $this->merchant->id, 'billing_cycle' => BillingCycle::Yearly]);

        changeTo(onPlan($this->starter), $yearly, '2026-10-16')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id');
    });

    it('rejects a second change on the same date', function () {
        $subscription = onPlan($this->starter);
        $third = Plan::factory()->create(['merchant_id' => $this->merchant->id]);

        changeTo($subscription, $this->pro, '2026-10-16')->assertOk();
        changeTo($subscription, $third, '2026-10-16')->assertConflict();

        expect($subscription->segments()->count())->toBe(2)
            ->and($subscription->fresh()->plan_id)->toBe($this->pro->id);
    });

    it('rejects a change dated before a scheduled one', function () {
        $subscription = onPlan($this->starter);
        $third = Plan::factory()->create(['merchant_id' => $this->merchant->id]);

        changeTo($subscription, $this->pro, '2026-10-20')->assertOk();
        changeTo($subscription, $third, '2026-10-10')->assertConflict();
    });

    it('rejects a change once canceled', function () {
        $subscription = onPlan($this->starter);
        app(CancelSubscription::class)->handle($subscription, CarbonImmutable::parse('2026-10-20'));

        changeTo($subscription, $this->pro, '2026-10-10')->assertConflict();
    });

    it('rejects a change between the period end and its invoice', function () {
        $subscription = onPlan($this->starter);
        $this->travelTo('2026-11-02 12:00:00');

        changeTo($subscription, $this->pro, '2026-11-15')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('effective_on');
    });
});

describe('tenant isolation', function () {
    it('returns 404 for another merchant subscription', function () {
        $other = Merchant::factory()->create(['api_key_hash' => Merchant::hashApiKey('other-key')]);
        $subscription = onPlan($this->starter);

        changeTo($subscription, $this->pro, '2026-10-16', 'other-key')->assertNotFound();

        expect($other->subscriptions()->count())->toBe(0)
            ->and($subscription->segments()->count())->toBe(1);
    });

    it('rejects a plan from another merchant', function () {
        changeTo(onPlan($this->starter), Plan::factory()->create(), '2026-10-16')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id');
    });

    it('requires an api key', function () {
        changeTo(onPlan($this->starter), $this->pro, '2026-10-16', 'wrong-key')->assertUnauthorized();
    });
});
