<?php

use App\Jobs\AggregateUsageChunk;
use App\Models\Customer;
use App\Models\DailyUsage;
use App\Models\UsageEvent;
use App\Services\Billing\UsageAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function rollUp(array $customerIds): void
{
    app(UsageAggregator::class)->rollUp($customerIds, '2026-10-01', '2026-10-31');
}

function unitsOn(Customer $customer, string $date): ?int
{
    return DailyUsage::where('customer_id', $customer->id)->where('usage_date', $date)->value('units');
}

beforeEach(function () {
    $this->customer = Customer::factory()->create();
});

it('sums events per customer per day', function () {
    UsageEvent::factory()->create(['customer_id' => $this->customer->id, 'usage_date' => '2026-10-10', 'quantity' => 5]);
    UsageEvent::factory()->create(['customer_id' => $this->customer->id, 'usage_date' => '2026-10-10', 'quantity' => 7]);
    UsageEvent::factory()->create(['customer_id' => $this->customer->id, 'usage_date' => '2026-10-11', 'quantity' => 3]);

    rollUp([$this->customer->id]);

    expect(unitsOn($this->customer, '2026-10-10'))->toBe(12)
        ->and(unitsOn($this->customer, '2026-10-11'))->toBe(3)
        ->and(DailyUsage::where('customer_id', $this->customer->id)->first()->merchant_id)
        ->toBe($this->customer->merchant_id);
});

it('gives the same totals when run twice', function () {
    UsageEvent::factory()->create(['customer_id' => $this->customer->id, 'usage_date' => '2026-10-10', 'quantity' => 5]);

    rollUp([$this->customer->id]);
    rollUp([$this->customer->id]);

    expect(unitsOn($this->customer, '2026-10-10'))->toBe(5)
        ->and(DailyUsage::count())->toBe(1);
});

it('picks up a late event by recomputing the day', function () {
    UsageEvent::factory()->create(['customer_id' => $this->customer->id, 'usage_date' => '2026-10-10', 'quantity' => 5]);
    rollUp([$this->customer->id]);

    UsageEvent::factory()->create(['customer_id' => $this->customer->id, 'usage_date' => '2026-10-10', 'quantity' => 2]);
    rollUp([$this->customer->id]);

    expect(unitsOn($this->customer, '2026-10-10'))->toBe(7);
});

it('ignores events outside the date range', function () {
    UsageEvent::factory()->create(['customer_id' => $this->customer->id, 'usage_date' => '2026-09-30']);
    UsageEvent::factory()->create(['customer_id' => $this->customer->id, 'usage_date' => '2026-11-01']);

    rollUp([$this->customer->id]);

    expect(DailyUsage::count())->toBe(0);
});

it('only touches the given customers', function () {
    $other = Customer::factory()->create();
    UsageEvent::factory()->create(['customer_id' => $other->id, 'usage_date' => '2026-10-10']);

    rollUp([$this->customer->id]);

    expect(DailyUsage::count())->toBe(0);
});

it('leaves a rollup in place if its raw events have been archived', function () {
    $event = UsageEvent::factory()->create(['customer_id' => $this->customer->id, 'usage_date' => '2026-10-10', 'quantity' => 5]);
    rollUp([$this->customer->id]);

    $event->delete();
    rollUp([$this->customer->id]);

    expect(unitsOn($this->customer, '2026-10-10'))->toBe(5);
});

describe('billing:aggregate', function () {
    it('queues one job per chunk of customers', function () {
        Queue::fake();
        config(['billing.chunk_size' => 2]);
        Customer::factory()->count(4)->create();

        $this->artisan('billing:aggregate')->assertSuccessful();

        Queue::assertPushed(AggregateUsageChunk::class, 3);
    });

    it('covers every customer exactly once across its chunks', function () {
        Queue::fake();
        config(['billing.chunk_size' => 2]);
        Customer::factory()->count(4)->create();

        $this->artisan('billing:aggregate');

        $queued = Queue::pushed(AggregateUsageChunk::class)
            ->flatMap(fn (AggregateUsageChunk $job) => $job->customerIds)
            ->sort()
            ->values()
            ->all();

        expect($queued)->toBe(Customer::orderBy('id')->pluck('id')->all());
    });

    it('looks back over the late window by default', function () {
        Queue::fake();
        $this->travelTo('2026-10-30 12:00:00');

        $this->artisan('billing:aggregate');

        Queue::assertPushed(AggregateUsageChunk::class, fn (AggregateUsageChunk $job) => $job->from === '2026-10-27' && $job->to === '2026-10-30');
    });

    it('accepts a longer look-back for a backfill', function () {
        Queue::fake();
        $this->travelTo('2026-10-30 12:00:00');

        $this->artisan('billing:aggregate --days=29');

        Queue::assertPushed(AggregateUsageChunk::class, fn (AggregateUsageChunk $job) => $job->from === '2026-10-01');
    });

    it('rolls usage up when the queue runs', function () {
        $this->travelTo('2026-10-30 12:00:00');
        UsageEvent::factory()->create(['customer_id' => $this->customer->id, 'usage_date' => '2026-10-30', 'quantity' => 9]);

        $this->artisan('billing:aggregate');

        expect(unitsOn($this->customer, '2026-10-30'))->toBe(9);
    });
});
