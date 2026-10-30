# Screen recording outline

A 5–10 minute narrated walkthrough. Timings add up to about 8 minutes. Speak in your
own words; these are prompts, not a script.

## Before you record

Run each of these once, then leave the server running in its own terminal:

```bash
php artisan cache:clear
```

```bash
php artisan migrate:fresh --seed
```

```bash
php artisan serve
```

Have these ready: the dashboard page, a terminal, and your editor with
`InvoiceCalculator.php`, `2026_10_30_000005_create_usage_events_table.php` and
`docs/load-test.md` open.

## 1. What it is (30 seconds)

- Multi-tenant metering and billing: usage comes in, rolls up daily, invoices at cycle end.
- The main idea: raw writes are kept apart from everything that reads.
- Show the architecture diagram at the top of the README.

## 2. Dashboard (1 minute)

Open `/dashboard`, and use merchant `1` with `demo-acme-key`.

- Projected overage: actual usage so far plus the daily average, through the real calculator.
- Top 5 customers this month.
- Churn: same days last month, up to yesterday, because a part month against a full one
  would flag everyone.

## 3. Idempotent usage (1 minute)

Run the `POST /api/usage` command from the README with today's date, twice.

- First call: `201`. Second call: `200 duplicate`.
- Explain that the unique index decides, so there's no read first and no race.
- Mention the 8-process test: 300 shared keys, exactly 300 rows.

## 4. Schema at 50L rows (1.5 minutes)

Open the `usage_events` migration.

- Monthly partitions, and why the date had to join the keys.
- One secondary index doing two jobs: idempotency and the aggregator's lookups.
- No foreign keys, and why that's acceptable here.
- `daily_usage` and its covering index.

## 5. Billing math (1.5 minutes)

Open `InvoiceCalculator.php`.

- Pure code with no database, so edge cases are easy to test.
- Integers: paise, and micros for rates.
- The overflow guard.
- Walk through the Starter to Pro example: 15/31 and 16/31 days, totalling ₹2,173.24.
- Segments snapshot pricing, so old usage keeps the old rate.

## 6. Queues and caching (1 minute)

- `billing:aggregate` chunks customers, and the rollup overwrites, so reruns are safe.
- The 3-day late window, and why invoicing waits for it.
- Duplicate invoice protection: the row lock, the due check, then the unique index.
- Cache invalidation after commit, and why that order matters.

## 7. What the load test found (1 minute)

Open `docs/load-test.md`.

- Results at 50L, and that this was a laptop with a 16 MB buffer pool.
- The honest weak spot: random-order ingest slows down.
- The dashboard bugs that only appeared at volume.

## 8. Trade-offs (30 seconds)

- What was left out on purpose.
- What you'd do next: CI on MariaDB, a real ingest limit test, then buffered writes.
- Close with how you used AI and where running the code corrected it.
