<?php

use App\Enums\BillingCycle;
use App\Models\Customer;
use App\Models\DailyUsage;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use App\Models\UsageEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps a subscription and its related rows inside one merchant', function () {
    $subscription = Subscription::factory()->create();

    expect($subscription->customer->merchant_id)->toBe($subscription->merchant_id)
        ->and($subscription->plan->merchant_id)->toBe($subscription->merchant_id)
        ->and($subscription->plan->billing_cycle)->toBe(BillingCycle::Monthly);
});

it('rejects a repeated idempotency key for the same customer and day', function () {
    $event = UsageEvent::factory()->create();

    UsageEvent::factory()->create([
        'customer_id' => $event->customer_id,
        'usage_date' => $event->usage_date,
        'idempotency_key' => $event->idempotency_key,
    ]);
})->throws(QueryException::class);

it('allows the same idempotency key for different customers', function () {
    $first = UsageEvent::factory()->create(['idempotency_key' => 'retry-1']);
    $second = UsageEvent::factory()->create(['idempotency_key' => 'retry-1']);

    expect($first->customer_id)->not->toBe($second->customer_id);
});

it('allows one rollup row per customer per day', function () {
    $usage = DailyUsage::factory()->create();

    DailyUsage::factory()->create([
        'customer_id' => $usage->customer_id,
        'usage_date' => $usage->usage_date,
    ]);
})->throws(QueryException::class);

it('allows one invoice per subscription period', function () {
    $invoice = Invoice::factory()->create();

    Invoice::factory()->create([
        'subscription_id' => $invoice->subscription_id,
        'period_start' => $invoice->period_start,
    ]);
})->throws(QueryException::class);

it('allows two segments in a subscription only on different start dates', function () {
    $segment = SubscriptionSegment::factory()->create();

    SubscriptionSegment::factory()->create([
        'subscription_id' => $segment->subscription_id,
        'starts_on' => $segment->starts_on,
    ]);
})->throws(QueryException::class);

it('deletes invoice lines with their invoice', function () {
    $line = InvoiceLine::factory()->create();

    $line->invoice->delete();

    expect(InvoiceLine::count())->toBe(0);
});

it('does not expose the merchant api key hash when serialized', function () {
    expect(Merchant::factory()->create()->toArray())->not->toHaveKey('api_key_hash');
});

it('scopes customer external ids to a merchant', function () {
    $customer = Customer::factory()->create(['external_id' => 'acme']);

    expect(Customer::factory()->create(['external_id' => 'acme'])->merchant_id)
        ->not->toBe($customer->merchant_id);

    Customer::factory()->create(['merchant_id' => $customer->merchant_id, 'external_id' => 'acme']);
})->throws(QueryException::class);

it('stores plan prices as integers', function () {
    $plan = Plan::factory()->create(['overage_rate_micros' => 2500])->fresh();

    expect($plan->overage_rate_micros)->toBe(2500);
});
