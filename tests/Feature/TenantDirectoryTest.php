<?php

use App\Models\Customer;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-30 12:00:00');

    $this->merchant = Merchant::factory()->create(['api_key_hash' => Merchant::hashApiKey('test-key')]);
    $this->customer = Customer::factory()->create(['merchant_id' => $this->merchant->id, 'external_id' => 'cust-1']);
});

function sendUsage(string $key, string $apiKey = 'test-key', string $customer = 'cust-1'): TestResponse
{
    return test()->postJson('/api/usage', [
        'customer_id' => $customer,
        'usage_date' => '2026-10-30',
        'quantity' => 1,
        'idempotency_key' => $key,
    ], ['X-Api-Key' => $apiKey]);
}

it('writes without reading the merchant or customer once cached', function () {
    sendUsage('first')->assertCreated();

    DB::flushQueryLog();
    DB::enableQueryLog();

    sendUsage('second')->assertCreated();

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and(DB::getQueryLog()[0]['query'])->toContain('insert or ignore into "usage_events"');
});

it('rejects a rotated api key immediately', function () {
    sendUsage('first')->assertCreated();

    $this->merchant->update(['api_key_hash' => Merchant::hashApiKey('new-key')]);

    sendUsage('second')->assertUnauthorized();
    sendUsage('third', 'new-key')->assertCreated();
});

it('rejects a deleted customer immediately', function () {
    sendUsage('first')->assertCreated();

    $this->customer->delete();

    sendUsage('second')->assertUnprocessable();
});

it('stops resolving a customer external id once it changes', function () {
    sendUsage('first')->assertCreated();

    $this->customer->update(['external_id' => 'cust-renamed']);

    sendUsage('second')->assertUnprocessable();
    sendUsage('third', customer: 'cust-renamed')->assertCreated();
});

it('accepts a customer created after a failed lookup', function () {
    sendUsage('first', customer: 'cust-new')->assertUnprocessable();

    Customer::factory()->create(['merchant_id' => $this->merchant->id, 'external_id' => 'cust-new']);

    sendUsage('second', customer: 'cust-new')->assertCreated();
});

it('keeps cached customers separate per merchant', function () {
    $other = Merchant::factory()->create(['api_key_hash' => Merchant::hashApiKey('other-key')]);
    $otherCustomer = Customer::factory()->create(['merchant_id' => $other->id, 'external_id' => 'cust-1']);

    sendUsage('first')->assertCreated();
    sendUsage('first', 'other-key')->assertCreated();

    expect($otherCustomer->id)->not->toBe($this->customer->id)
        ->and(DB::table('usage_events')->pluck('customer_id')->sort()->values()->all())
        ->toBe([$this->customer->id, $otherCustomer->id]);
});
