<?php

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\UsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-30 12:00:00');

    $this->merchant = Merchant::factory()->create(['api_key_hash' => Merchant::hashApiKey('test-key')]);
    $this->customer = Customer::factory()->create(['merchant_id' => $this->merchant->id, 'external_id' => 'cust-1']);
});

function postUsage(array $overrides = [], string $apiKey = 'test-key'): TestResponse
{
    return test()->postJson('/api/usage', array_merge([
        'customer_id' => 'cust-1',
        'usage_date' => '2026-10-30',
        'quantity' => 10,
        'idempotency_key' => 'evt-1',
    ], $overrides), ['X-Api-Key' => $apiKey]);
}

describe('recording', function () {
    it('stores a usage event against the merchant customer', function () {
        postUsage()->assertCreated()->assertExactJson(['status' => 'recorded']);

        $event = UsageEvent::sole();

        expect($event->merchant_id)->toBe($this->merchant->id)
            ->and($event->customer_id)->toBe($this->customer->id)
            ->and($event->usage_date->toDateString())->toBe('2026-10-30')
            ->and($event->quantity)->toBe(10);
    });

    it('stores separate events with different keys', function () {
        postUsage(['idempotency_key' => 'evt-1'])->assertCreated();
        postUsage(['idempotency_key' => 'evt-2'])->assertCreated();

        expect(UsageEvent::sum('quantity'))->toEqual(20);
    });
});

describe('idempotency', function () {
    it('does not double-count a retried request', function () {
        postUsage()->assertCreated();
        postUsage()->assertOk()->assertExactJson(['status' => 'duplicate']);
        postUsage()->assertOk();

        expect(UsageEvent::count())->toBe(1);
    });

    it('keeps the original quantity when a retry changes it', function () {
        postUsage(['quantity' => 10])->assertCreated();
        postUsage(['quantity' => 999])->assertOk();

        expect(UsageEvent::sole()->quantity)->toBe(10);
    });

    it('lets two customers use the same key', function () {
        Customer::factory()->create(['merchant_id' => $this->merchant->id, 'external_id' => 'cust-2']);

        postUsage(['customer_id' => 'cust-1'])->assertCreated();
        postUsage(['customer_id' => 'cust-2'])->assertCreated();

        expect(UsageEvent::count())->toBe(2);
    });
});

describe('tenant isolation', function () {
    it('rejects a customer belonging to another merchant', function () {
        Customer::factory()->create(['external_id' => 'other-merchant-customer']);

        postUsage(['customer_id' => 'other-merchant-customer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');

        expect(UsageEvent::count())->toBe(0);
    });

    it('resolves a shared external id to the calling merchant', function () {
        $other = Merchant::factory()->create(['api_key_hash' => Merchant::hashApiKey('other-key')]);
        $otherCustomer = Customer::factory()->create(['merchant_id' => $other->id, 'external_id' => 'cust-1']);

        postUsage(apiKey: 'other-key')->assertCreated();

        expect(UsageEvent::sole()->customer_id)->toBe($otherCustomer->id);
    });
});

describe('authentication', function () {
    it('rejects a missing api key', function () {
        $this->postJson('/api/usage')->assertUnauthorized();
    });

    it('rejects a wrong api key', function () {
        postUsage(apiKey: 'wrong-key')->assertUnauthorized();
    });
});

describe('validation', function () {
    it('rejects invalid input', function (array $overrides, string $field) {
        postUsage($overrides)->assertUnprocessable()->assertJsonValidationErrors($field);

        expect(UsageEvent::count())->toBe(0);
    })->with([
        'unknown customer' => [['customer_id' => 'nobody'], 'customer_id'],
        'missing customer' => [['customer_id' => null], 'customer_id'],
        'zero quantity' => [['quantity' => 0], 'quantity'],
        'negative quantity' => [['quantity' => -1], 'quantity'],
        'fractional quantity' => [['quantity' => 1.5], 'quantity'],
        'quantity over the limit' => [['quantity' => 1_000_001], 'quantity'],
        'malformed date' => [['usage_date' => '30-10-2026'], 'usage_date'],
        'future date' => [['usage_date' => '2026-10-31'], 'usage_date'],
        'date past the late window' => [['usage_date' => '2026-10-26'], 'usage_date'],
        'missing key' => [['idempotency_key' => null], 'idempotency_key'],
        'key too long' => [['idempotency_key' => str_repeat('a', 65)], 'idempotency_key'],
        'non-ascii key' => [['idempotency_key' => 'clé-1'], 'idempotency_key'],
    ]);

    it('accepts a date on the last day of the late window', function () {
        postUsage(['usage_date' => '2026-10-27'])->assertCreated();
    });
});

describe('rate limiting', function () {
    beforeEach(fn () => config(['billing.usage.rate_limit_per_minute' => 2]));

    it('rejects requests over the limit', function () {
        postUsage(['idempotency_key' => 'a'])->assertCreated();
        postUsage(['idempotency_key' => 'b'])->assertCreated();

        postUsage(['idempotency_key' => 'c'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        expect(UsageEvent::count())->toBe(2);
    });

    it('limits each merchant separately', function () {
        $other = Merchant::factory()->create(['api_key_hash' => Merchant::hashApiKey('other-key')]);
        Customer::factory()->create(['merchant_id' => $other->id, 'external_id' => 'cust-1']);

        postUsage(['idempotency_key' => 'a']);
        postUsage(['idempotency_key' => 'b']);
        postUsage(['idempotency_key' => 'c'])->assertTooManyRequests();

        postUsage(['idempotency_key' => 'a'], 'other-key')->assertCreated();
    });

    it('resets after the window', function () {
        postUsage(['idempotency_key' => 'a']);
        postUsage(['idempotency_key' => 'b']);
        postUsage(['idempotency_key' => 'c'])->assertTooManyRequests();

        $this->travel(61)->seconds();

        postUsage(['idempotency_key' => 'c'])->assertCreated();
    });
});
