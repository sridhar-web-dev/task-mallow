<?php

use App\DTOs\BillingPeriod;
use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;

it('finds the calendar month containing a date', function () {
    $period = BillingPeriod::containing(BillingCycle::Monthly, CarbonImmutable::parse('2026-10-15'));

    expect($period->start->toDateString())->toBe('2026-10-01')
        ->and($period->end->toDateString())->toBe('2026-11-01')
        ->and($period->days())->toBe(31);
});

it('does not overflow from the last day of a long month', function () {
    $period = BillingPeriod::containing(BillingCycle::Monthly, CarbonImmutable::parse('2026-01-31'));

    expect($period->end->toDateString())->toBe('2026-02-01');
});

it('crosses a year boundary from December', function () {
    $period = BillingPeriod::containing(BillingCycle::Monthly, CarbonImmutable::parse('2026-12-31'));

    expect($period->end->toDateString())->toBe('2027-01-01');
});

it('counts February correctly in normal and leap years', function (string $date, int $days) {
    expect(BillingPeriod::containing(BillingCycle::Monthly, CarbonImmutable::parse($date))->days())->toBe($days);
})->with([
    ['2026-02-10', 28],
    ['2028-02-10', 29],
]);

it('finds the calendar year containing a date', function (string $date, int $days) {
    $period = BillingPeriod::containing(BillingCycle::Yearly, CarbonImmutable::parse($date));

    expect($period->start->format('m-d'))->toBe('01-01')
        ->and($period->days())->toBe($days);
})->with([
    ['2026-07-01', 365],
    ['2028-07-01', 366],
]);

it('rejects a period that ends before it starts', function () {
    new BillingPeriod(CarbonImmutable::parse('2026-10-02'), CarbonImmutable::parse('2026-10-01'));
})->throws(InvalidArgumentException::class);

it('rejects an empty period', function () {
    new BillingPeriod(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-01'));
})->throws(InvalidArgumentException::class);
