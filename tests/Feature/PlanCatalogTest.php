<?php

use App\Models\Plan;
use App\Services\PlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->catalog = app(PlanCatalog::class);
    $this->plan = Plan::factory()->create(['name' => 'Starter', 'base_price_minor' => 99900]);
});

function planQueries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();

    return count(array_filter(
        DB::getQueryLog(),
        fn (array $entry) => str_contains($entry['query'], '"plans"'),
    ));
}

describe('find', function () {
    it('returns pricing for a plan', function () {
        $pricing = $this->catalog->find($this->plan->id);

        expect($pricing->name)->toBe('Starter')
            ->and($pricing->basePriceMinor)->toBe(99900)
            ->and($pricing->merchantId)->toBe($this->plan->merchant_id);
    });

    it('reads the database once across repeated lookups', function () {
        $queries = planQueries(function () {
            $this->catalog->find($this->plan->id);
            $this->catalog->find($this->plan->id);
            $this->catalog->find($this->plan->id);
        });

        expect($queries)->toBe(1);
    });

    it('returns null for an unknown plan', function () {
        expect($this->catalog->find(999))->toBeNull();
    });
});

describe('invalidation', function () {
    it('serves the new price straight after a plan is updated', function () {
        $this->catalog->find($this->plan->id);

        $this->plan->update(['base_price_minor' => 149900]);

        expect($this->catalog->find($this->plan->id)->basePriceMinor)->toBe(149900);
    });

    it('stops serving a deleted plan', function () {
        $this->catalog->find($this->plan->id);

        $this->plan->delete();

        expect($this->catalog->find($this->plan->id))->toBeNull();
    });

    it('leaves other merchants cached', function () {
        $other = Plan::factory()->create();
        $this->catalog->find($other->id);

        $this->plan->update(['base_price_minor' => 1]);

        expect(planQueries(fn () => $this->catalog->find($other->id)))->toBe(0);
    });

    it('waits for the transaction to commit before clearing the cache', function () {
        $this->catalog->find($this->plan->id);

        DB::transaction(function () {
            $this->plan->update(['base_price_minor' => 149900]);

            expect(planQueries(fn () => $this->catalog->find($this->plan->id)))->toBe(0);
        });

        expect($this->catalog->find($this->plan->id)->basePriceMinor)->toBe(149900);
    });

    it('keeps the old price after a rolled-back update', function () {
        $this->catalog->find($this->plan->id);

        try {
            DB::transaction(function () {
                $this->plan->update(['base_price_minor' => 149900]);

                throw new RuntimeException('Rolled back.');
            });
        } catch (RuntimeException) {
        }

        expect($this->catalog->find($this->plan->id)->basePriceMinor)->toBe(99900);
    });

    it('recovers from a bulk update when the ttl expires', function () {
        $this->catalog->find($this->plan->id);

        // Query-builder updates fire no model events, so the observer never runs.
        Plan::whereKey($this->plan->id)->update(['base_price_minor' => 149900]);

        expect($this->catalog->find($this->plan->id)->basePriceMinor)->toBe(99900);

        $this->travel(config('billing.cache.ttl_seconds') + 1)->seconds();

        expect($this->catalog->find($this->plan->id)->basePriceMinor)->toBe(149900);
    });
});
