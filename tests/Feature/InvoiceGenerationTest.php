<?php

use App\Actions\CancelSubscription;
use App\Actions\SubscribeCustomer;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\CloseBillingChunk;
use App\Jobs\GenerateInvoice;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create();
    // ₹999 a month, 10,000 units included, ₹0.05 per extra unit.
    $this->plan = Plan::factory()->create(['merchant_id' => $this->merchant->id]);
});

function subscribed(string $startsOn = '2026-10-01', ?Plan $plan = null): Subscription
{
    $plan ??= test()->plan;
    $customer = Customer::factory()->create(['merchant_id' => $plan->merchant_id]);

    return app(SubscribeCustomer::class)->handle($customer, $plan->id, CarbonImmutable::parse($startsOn));
}

function usage(Subscription $subscription, string $date, int $quantity): void
{
    UsageEvent::factory()->create([
        'customer_id' => $subscription->customer_id,
        'usage_date' => $date,
        'quantity' => $quantity,
    ]);
}

function runBillingOn(string $date): void
{
    test()->travelTo($date.' 00:30:00');
    test()->artisan('billing:invoice')->assertSuccessful();
}

describe('a full cycle', function () {
    it('invoices the base price plus overage', function () {
        $subscription = subscribed();
        usage($subscription, '2026-10-05', 6000);
        usage($subscription, '2026-10-20', 4001);

        runBillingOn('2026-11-04');

        $invoice = Invoice::sole();

        expect($invoice->base_amount_minor)->toBe(99900)
            ->and($invoice->overage_amount_minor)->toBe(5)
            ->and($invoice->total_minor)->toBe(99905)
            ->and($invoice->status)->toBe(InvoiceStatus::Issued)
            ->and($invoice->currency)->toBe('INR')
            ->and($invoice->issued_at)->not->toBeNull()
            ->and($invoice->period_start->toDateString())->toBe('2026-10-01')
            ->and($invoice->period_end->toDateString())->toBe('2026-11-01')
            ->and($invoice->lines)->toHaveCount(2);
    });

    it('invoices the base price with no usage', function () {
        subscribed();

        runBillingOn('2026-11-04');

        expect(Invoice::sole()->total_minor)->toBe(99900);
    });

    it('stores each line with its segment and proration', function () {
        subscribed();

        runBillingOn('2026-11-04');

        $line = Invoice::sole()->lines->sole();

        expect($line->subscription_segment_id)->not->toBeNull()
            ->and($line->proration_days)->toBe(31)
            ->and($line->cycle_days)->toBe(31);
    });
});

it('prorates a subscription that started mid-cycle', function () {
    $subscription = subscribed('2026-10-15');
    usage($subscription, '2026-10-20', 5484);

    runBillingOn('2026-11-04');

    // 17 of 31 days: base 54784, allowance 5483, one unit over.
    expect(Invoice::sole()->total_minor)->toBe(54784 + 5);
});

it('prorates a yearly plan against the whole year', function () {
    $yearly = Plan::factory()->create([
        'merchant_id' => $this->merchant->id,
        'billing_cycle' => BillingCycle::Yearly,
        'base_price_minor' => 365000,
    ]);
    subscribed('2026-07-01', $yearly);

    runBillingOn('2027-01-04');

    expect(Invoice::sole()->base_amount_minor)->toBe(184000);
});

describe('timing', function () {
    it('waits for the late window before invoicing', function () {
        subscribed();

        runBillingOn('2026-11-03');
        expect(Invoice::count())->toBe(0);

        runBillingOn('2026-11-04');
        expect(Invoice::count())->toBe(1);
    });

    it('includes an event that arrives on the last day of the late window', function () {
        $subscription = subscribed();

        $this->travelTo('2026-11-03 23:00:00');
        usage($subscription, '2026-10-31', 10001);

        runBillingOn('2026-11-04');

        expect(Invoice::sole()->overage_amount_minor)->toBe(5);
    });

    it('leaves usage from the next period out of the invoice', function () {
        $subscription = subscribed();
        usage($subscription, '2026-11-01', 50000);

        runBillingOn('2026-11-04');

        expect(Invoice::sole()->overage_amount_minor)->toBe(0);
    });

    it('invoices no period that has not ended', function () {
        subscribed();

        runBillingOn('2026-10-31');

        expect(Invoice::count())->toBe(0);
    });
});

describe('idempotency', function () {
    it('does not invoice twice when the sweep runs twice', function () {
        subscribed();

        runBillingOn('2026-11-04');
        runBillingOn('2026-11-04');

        expect(Invoice::count())->toBe(1);
    });

    it('treats a duplicated job as a no-op', function () {
        $subscription = subscribed();
        runBillingOn('2026-11-04');

        GenerateInvoice::dispatch($subscription->id);

        expect(Invoice::count())->toBe(1)
            ->and($subscription->segments()->count())->toBe(2);
    });

    it('catches up one period per sweep after an outage', function () {
        subscribed();

        runBillingOn('2026-12-10');
        runBillingOn('2026-12-10');
        runBillingOn('2026-12-10');

        expect(Invoice::orderBy('period_start')->pluck('period_start')->map->toDateString()->all())
            ->toBe(['2026-10-01', '2026-11-01']);
    });
});

describe('rolling over', function () {
    it('moves the subscription to the next calendar cycle', function () {
        $subscription = subscribed('2026-10-15');

        runBillingOn('2026-11-04');

        $subscription->refresh();

        expect($subscription->current_period_start->toDateString())->toBe('2026-11-01')
            ->and($subscription->current_period_end->toDateString())->toBe('2026-12-01');
    });

    it('closes the old segment and opens a new one', function () {
        $subscription = subscribed();

        runBillingOn('2026-11-04');

        [$closed, $open] = $subscription->segments()->orderBy('starts_on')->get()->all();

        expect($closed->ends_on->toDateString())->toBe('2026-11-01')
            ->and($open->starts_on->toDateString())->toBe('2026-11-01')
            ->and($open->ends_on)->toBeNull();
    });

    it('bills an edited price from the next period only', function () {
        $subscription = subscribed();

        $this->plan->update(['base_price_minor' => 149900]);

        runBillingOn('2026-11-04');

        expect(Invoice::sole()->base_amount_minor)->toBe(99900)
            ->and($subscription->segments()->whereNull('ends_on')->sole()->base_price_minor)->toBe(149900);
    });

    it('does not change an issued invoice when the plan changes', function () {
        subscribed();
        runBillingOn('2026-11-04');

        $this->plan->update(['base_price_minor' => 1]);

        expect(Invoice::sole()->fresh()->total_minor)->toBe(99900);
    });
});

it('bills each segment at its own rate across a plan change', function () {
    $pro = Plan::factory()->create([
        'merchant_id' => $this->merchant->id,
        'base_price_minor' => 299900,
        'included_units' => 50000,
        'overage_rate_micros' => 20000,
    ]);
    $subscription = subscribed();
    $subscription->segments()->sole()->update(['ends_on' => '2026-10-16']);
    $subscription->segments()->create([
        'plan_id' => $pro->id,
        'starts_on' => '2026-10-16',
        'base_price_minor' => 299900,
        'included_units' => 50000,
        'overage_rate_micros' => 20000,
    ]);
    $subscription->update(['plan_id' => $pro->id]);
    usage($subscription, '2026-10-15', 6000);
    usage($subscription, '2026-10-16', 30000);

    runBillingOn('2026-11-04');

    // Worked by hand in InvoiceCalculatorTest.
    expect(Invoice::sole()->total_minor)->toBe(217324)
        ->and(Invoice::sole()->lines)->toHaveCount(4);
});

describe('cancellation', function () {
    it('sends a final prorated invoice and stops billing', function () {
        $subscription = subscribed();
        app(CancelSubscription::class)->handle($subscription, CarbonImmutable::parse('2026-10-16'));

        runBillingOn('2026-10-19');
        runBillingOn('2026-11-04');

        $subscription->refresh();

        expect(Invoice::sole()->base_amount_minor)->toBe(48339)
            ->and(Invoice::sole()->period_end->toDateString())->toBe('2026-10-16')
            ->and($subscription->status)->toBe(SubscriptionStatus::Canceled)
            ->and($subscription->segments()->count())->toBe(1);
    });

    it('rejects a date outside the current segment', function () {
        $subscription = subscribed();

        app(CancelSubscription::class)->handle($subscription, CarbonImmutable::parse('2026-10-01'));
    })->throws(DomainException::class);

    it('rejects a second cancellation', function () {
        $subscription = subscribed();
        $cancel = app(CancelSubscription::class);

        $cancel->handle($subscription, CarbonImmutable::parse('2026-10-16'));
        $cancel->handle($subscription, CarbonImmutable::parse('2026-10-20'));
    })->throws(DomainException::class);
});

describe('subscribing', function () {
    it('opens a segment with a pricing snapshot', function () {
        $segment = subscribed('2026-10-15')->segments()->sole();

        expect($segment->starts_on->toDateString())->toBe('2026-10-15')
            ->and($segment->ends_on)->toBeNull()
            ->and($segment->base_price_minor)->toBe(99900);
    });

    it('rejects a second active subscription', function () {
        $subscription = subscribed();

        app(SubscribeCustomer::class)->handle($subscription->customer, $this->plan->id, CarbonImmutable::parse('2026-10-02'));
    })->throws(DomainException::class, 'already has');

    it('rejects a plan from another merchant', function () {
        subscribed(plan: Plan::factory()->create());
        $customer = Customer::factory()->create(['merchant_id' => $this->merchant->id]);

        app(SubscribeCustomer::class)->handle($customer, Plan::factory()->create()->id, CarbonImmutable::parse('2026-10-01'));
    })->throws(DomainException::class, 'does not belong');

    it('rejects an inactive plan', function () {
        $this->plan->update(['is_active' => false]);

        subscribed();
    })->throws(DomainException::class, 'no longer available');
});

describe('billing:invoice', function () {
    it('queues one job per chunk of due subscriptions', function () {
        subscribed();
        subscribed();
        subscribed();
        Queue::fake();
        config(['billing.chunk_size' => 2]);

        runBillingOn('2026-11-04');

        Queue::assertPushed(CloseBillingChunk::class, 2);
    });

    it('invoices each customer on only their own usage', function () {
        $first = subscribed();
        $second = subscribed();
        usage($first, '2026-10-10', 10001);

        runBillingOn('2026-11-04');

        expect($first->invoices()->sole()->overage_amount_minor)->toBe(5)
            ->and($second->invoices()->sole()->overage_amount_minor)->toBe(0);
    });

    it('takes a final rollup without waiting for the hourly job', function () {
        $subscription = subscribed();
        usage($subscription, '2026-10-10', 10001);

        runBillingOn('2026-11-04');

        expect($subscription->customer->dailyUsage()->sole()->units)->toBe(10001);
    });
});
