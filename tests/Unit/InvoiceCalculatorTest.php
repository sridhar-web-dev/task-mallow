<?php

use App\DTOs\BillingPeriod;
use App\DTOs\BillingSegment;
use App\DTOs\InvoiceCalculation;
use App\DTOs\InvoiceLineData;
use App\Enums\InvoiceLineType;
use App\Services\Billing\InvoiceCalculator;
use Carbon\CarbonImmutable;

function period(string $start, string $end): BillingPeriod
{
    return new BillingPeriod(CarbonImmutable::parse($start), CarbonImmutable::parse($end));
}

function october(): BillingPeriod
{
    return period('2026-10-01', '2026-11-01');
}

// ₹999 a month, 10,000 units included, ₹0.05 per extra unit.
function starter(string $start, string $end, int $usage): BillingSegment
{
    return new BillingSegment('Starter', period($start, $end), 99900, 10000, 50000, $usage);
}

// ₹2,999 a month, 50,000 units included, ₹0.02 per extra unit.
function pro(string $start, string $end, int $usage): BillingSegment
{
    return new BillingSegment('Pro', period($start, $end), 299900, 50000, 20000, $usage);
}

function bill(BillingPeriod $cycle, BillingSegment ...$segments): InvoiceCalculation
{
    return (new InvoiceCalculator)->calculate($cycle, array_values($segments));
}

/**
 * @return list<InvoiceLineData>
 */
function overageLines(InvoiceCalculation $invoice): array
{
    return array_values(array_filter(
        $invoice->lines,
        fn (InvoiceLineData $line) => $line->type === InvoiceLineType::Overage,
    ));
}

describe('a full cycle', function () {
    it('charges only the base price when usage is under the allowance', function () {
        $invoice = bill(october(), starter('2026-10-01', '2026-11-01', 9999));

        expect($invoice->baseAmountMinor)->toBe(99900)
            ->and($invoice->overageAmountMinor)->toBe(0)
            ->and($invoice->totalMinor)->toBe(99900)
            ->and($invoice->lines)->toHaveCount(1);
    });

    it('charges the base price with zero usage', function () {
        expect(bill(october(), starter('2026-10-01', '2026-11-01', 0))->totalMinor)->toBe(99900);
    });

    it('charges no overage at exactly the allowance', function () {
        expect(bill(october(), starter('2026-10-01', '2026-11-01', 10000))->overageAmountMinor)->toBe(0);
    });

    it('charges one unit of overage just past the allowance', function () {
        $invoice = bill(october(), starter('2026-10-01', '2026-11-01', 10001));

        expect($invoice->overageAmountMinor)->toBe(5)
            ->and($invoice->totalMinor)->toBe(99905);
    });

    it('charges all usage as overage when the plan includes none', function () {
        $segment = new BillingSegment('Metered', october(), 0, 0, 50000, 100);

        $invoice = bill(october(), $segment);

        expect($invoice->baseAmountMinor)->toBe(0)
            ->and($invoice->overageAmountMinor)->toBe(500);
    });
});

describe('proration for a mid-cycle start', function () {
    it('prorates the base price and the allowance by days', function () {
        $invoice = bill(october(), starter('2026-10-15', '2026-11-01', 0));

        // 99900 x 17/31 = 54783.87, rounded up.
        expect($invoice->baseAmountMinor)->toBe(54784)
            ->and($invoice->lines[0]->prorationDays)->toBe(17)
            ->and($invoice->lines[0]->cycleDays)->toBe(31);
    });

    it('rounds the prorated allowance down', function () {
        // 10000 x 17/31 = 5483.87, so 5483 units are included.
        $atAllowance = bill(october(), starter('2026-10-15', '2026-11-01', 5483));
        $pastAllowance = bill(october(), starter('2026-10-15', '2026-11-01', 5484));

        expect($atAllowance->overageAmountMinor)->toBe(0)
            ->and($pastAllowance->overageAmountMinor)->toBe(5);
    });

    it('bills a single day against a 31 day cycle', function () {
        // 99900 / 31 = 3222.58; 10000 / 31 = 322.58, so 322 units are included.
        $invoice = bill(october(), starter('2026-10-31', '2026-11-01', 323));

        expect($invoice->baseAmountMinor)->toBe(3223)
            ->and($invoice->overageAmountMinor)->toBe(5);
    });

    it('uses the length of the actual month', function () {
        $february = period('2026-02-01', '2026-03-01');

        // 99900 x 14/28 = 49950 exactly.
        expect(bill($february, starter('2026-02-15', '2026-03-01', 0))->baseAmountMinor)->toBe(49950);
    });

    it('prorates a yearly plan across a leap year', function () {
        $year = period('2028-01-01', '2029-01-01');
        $segment = new BillingSegment('Annual', period('2028-07-01', '2029-01-01'), 3660000, 0, 0, 0);

        // 184 of 366 days.
        expect(bill($year, $segment)->baseAmountMinor)->toBe(1840000);
    });

    it('prorates a subscription canceled before the cycle ends', function () {
        expect(bill(october(), starter('2026-10-01', '2026-10-16', 0))->baseAmountMinor)->toBe(48339);
    });
});

describe('rounding', function () {
    it('rounds half a paisa up', function () {
        $february = period('2026-02-01', '2026-03-01');
        $segment = new BillingSegment('Tiny', period('2026-02-01', '2026-02-02'), 14, 0, 0, 0);

        expect(bill($february, $segment)->baseAmountMinor)->toBe(1);
    });

    it('rounds overage per line at half a paisa', function (int $rateMicros, int $expected) {
        $segment = new BillingSegment('Metered', october(), 0, 0, $rateMicros, 1);

        expect(bill(october(), $segment)->overageAmountMinor)->toBe($expected);
    })->with([
        'just under half' => [4999, 0],
        'exactly half' => [5000, 1],
    ]);

    it('keeps rates below one paisa exact', function () {
        // ₹0.0025 x 1000 units = ₹2.50.
        $segment = new BillingSegment('Metered', october(), 0, 0, 2500, 1000);

        expect(bill(october(), $segment)->overageAmountMinor)->toBe(250);
    });

    it('fails loudly rather than overflow an invoice', function () {
        $segment = new BillingSegment('Huge', october(), 0, 0, PHP_INT_MAX, 2);

        bill(october(), $segment);
    })->throws(OverflowException::class);
});

describe('a mid-cycle plan change', function () {
    it('bills an upgrade in two segments at each plan rate', function () {
        $invoice = bill(
            october(),
            starter('2026-10-01', '2026-10-16', 6000),
            pro('2026-10-16', '2026-11-01', 30000),
        );

        // Starter 15/31: base 48339; allowance 4838; 1162 over at 5 paise = 5810.
        // Pro 16/31: base 154787; allowance 25806; 4194 over at 2 paise = 8388.
        expect($invoice->baseAmountMinor)->toBe(48339 + 154787)
            ->and($invoice->overageAmountMinor)->toBe(5810 + 8388)
            ->and($invoice->totalMinor)->toBe(217324)
            ->and($invoice->lines)->toHaveCount(4);
    });

    it('bills a downgrade without offsetting usage between segments', function () {
        $invoice = bill(
            october(),
            pro('2026-10-01', '2026-10-16', 0),
            starter('2026-10-16', '2026-11-01', 6000),
        );

        // Pro's unused allowance does not cover Starter's 839 units of overage.
        expect($invoice->baseAmountMinor)->toBe(145113 + 51561)
            ->and($invoice->overageAmountMinor)->toBe(4195)
            ->and($invoice->totalMinor)->toBe(200869);
    });

    it('charges each segment at its own overage rate', function () {
        $invoice = bill(
            october(),
            starter('2026-10-01', '2026-10-16', 5838),
            pro('2026-10-16', '2026-11-01', 26806),
        );

        $lines = overageLines($invoice);

        expect($lines)->toHaveCount(2)
            ->and($lines[0]->quantity)->toBe(1000)
            ->and($lines[0]->amountMinor)->toBe(5000)
            ->and($lines[1]->quantity)->toBe(1000)
            ->and($lines[1]->amountMinor)->toBe(2000);
    });

    it('handles two changes in one cycle', function () {
        $invoice = bill(
            october(),
            starter('2026-10-01', '2026-10-11', 0),
            pro('2026-10-11', '2026-10-21', 0),
            starter('2026-10-21', '2026-11-01', 0),
        );

        // 99900 x 10/31 = 32225.81; 299900 x 10/31 = 96741.94; 99900 x 11/31 = 35448.39.
        expect($invoice->baseAmountMinor)->toBe(32226 + 96742 + 35448)
            ->and($invoice->lines)->toHaveCount(3);
    });

    it('handles a change on the last day of the cycle', function () {
        $invoice = bill(
            october(),
            starter('2026-10-01', '2026-10-31', 0),
            pro('2026-10-31', '2026-11-01', 0),
        );

        expect($invoice->lines[1]->prorationDays)->toBe(1)
            ->and($invoice->baseAmountMinor)->toBe(96677 + 9674);
    });

    it('combines a mid-cycle start with a plan change', function () {
        $invoice = bill(
            october(),
            starter('2026-10-15', '2026-10-25', 0),
            pro('2026-10-25', '2026-11-01', 0),
        );

        // 10/31 of Starter and 7/31 of Pro: 14 days before the start are unbilled.
        expect($invoice->baseAmountMinor)->toBe(32226 + 67719);
    });

    it('loses at most one unit of allowance per split to rounding down', function () {
        // 4838 + 5161 = 9999 of the 10,000 units a single segment would include.
        $invoice = bill(
            october(),
            starter('2026-10-01', '2026-10-16', 4839),
            starter('2026-10-16', '2026-11-01', 5161),
        );

        expect(overageLines($invoice))->toHaveCount(1)
            ->and(overageLines($invoice)[0]->quantity)->toBe(1);
    });

    it('sorts segments before billing them', function () {
        $invoice = bill(
            october(),
            pro('2026-10-16', '2026-11-01', 0),
            starter('2026-10-01', '2026-10-16', 0),
        );

        expect($invoice->lines[0]->description)->toContain('Starter')
            ->and($invoice->lines[1]->description)->toContain('Pro');
    });

    it('keeps the segment id on every line', function () {
        $segment = new BillingSegment('Starter', october(), 99900, 0, 50000, 1, segmentId: 7);

        $invoice = bill(october(), $segment);

        expect(array_column($invoice->lines, 'segmentId'))->toBe([7, 7]);
    });
});

it('totals exactly the sum of its lines', function () {
    $invoice = bill(
        october(),
        starter('2026-10-01', '2026-10-16', 6000),
        pro('2026-10-16', '2026-11-01', 30000),
    );

    expect($invoice->totalMinor)->toBe(array_sum(array_column($invoice->lines, 'amountMinor')));
});

describe('invalid input', function () {
    it('rejects an invoice with no segments', function () {
        bill(october());
    })->throws(InvalidArgumentException::class, 'at least one segment');

    it('rejects a segment outside the cycle', function () {
        bill(october(), starter('2026-09-30', '2026-11-01', 0));
    })->throws(InvalidArgumentException::class, 'inside the billing cycle');

    it('rejects a gap between segments', function () {
        bill(october(), starter('2026-10-01', '2026-10-10', 0), pro('2026-10-11', '2026-11-01', 0));
    })->throws(InvalidArgumentException::class, 'contiguous');

    it('rejects overlapping segments', function () {
        bill(october(), starter('2026-10-01', '2026-10-12', 0), pro('2026-10-11', '2026-11-01', 0));
    })->throws(InvalidArgumentException::class, 'contiguous');
});
