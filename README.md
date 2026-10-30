# Subscription Billing and Usage Metering

A multi-tenant Laravel 12 backend that meters customer usage against subscription
plans and produces invoices. It covers idempotent high-volume usage ingest, queued
daily rollups, prorated billing with overage, mid-cycle plan changes, cached pricing
and a merchant dashboard.

| Topic | Where |
|---|---|
| Schema and how it holds up at 50L+ rows | [docs/schema.md](docs/schema.md) |
| Cache contents and invalidation | [docs/caching.md](docs/caching.md) |
| Measured results at 50L rows | [docs/load-test.md](docs/load-test.md) |
| Screen recording outline | [docs/walkthrough.md](docs/walkthrough.md) |
| How AI tools were used | [prompts/README.md](prompts/README.md) |

## Demo access

After [setup](#setup), open <http://localhost:8000/dashboard> and enter one of these
merchant logins. Use the same API key in the `X-Api-Key` header for API calls.

| Merchant | Merchant ID | API key |
|---|---|---|
| Acme Cloud | `1` | `demo-acme-key` |
| Nimbus APIs | `2` | `demo-nimbus-key` |

These are seeded test credentials for local use only.

## Architecture

```text
POST /api/usage ──► usage_events ──(hourly, chunked)──► daily_usage ──► dashboard
  unique key         raw, partitioned                   one row per       (cached 5 min)
  rejects retries    append-only                        customer per day
                                                             │
subscription_segments ───────────────────────────────────────┤
  pricing snapshot per stretch on a plan                     ▼
                                               daily sweep ► invoices, invoice_lines
```

**Writes and reads are separated.** `POST /usage` only appends to `usage_events`.
Billing and the dashboard read `daily_usage`, so nothing user-facing scans raw events.

**Billing math is pure.** [InvoiceCalculator](app/Services/Billing/InvoiceCalculator.php)
has no database, clock or framework dependency. Invoices and the dashboard forecast
both go through it, so they cannot disagree.

**Segments carry the pricing.** A `subscription_segments` row is one stretch of a
billing period on one plan, with its price copied in. A plan change closes one segment
and opens the next, so old usage keeps the old rate. Editing a plan cannot change an
open period or an issued invoice.

### Code layout

| Location | Responsibility |
|---|---|
| `app/Http` | Thin controllers, form requests and API key middleware |
| `app/Actions` | One use case each: `RecordUsage`, `ChangePlan`, `SubscribeCustomer`, `CancelSubscription` |
| `app/Services/Billing` | `InvoiceCalculator`, `UsageAggregator`, `InvoiceGenerator` |
| `app/Services/Dashboard` | `MerchantDashboard`, `OverageProjector` |
| `app/Services` | Cached lookups: `PlanCatalog`, `TenantDirectory` |
| `app/Jobs` | Queued work: `AggregateUsageChunk`, `CloseBillingChunk`, `GenerateInvoice` |
| `app/DTOs` | Typed values passed between layers |
| `app/Observers` | Cache invalidation |

## Setup

Requires PHP 8.2+, Composer, and MariaDB or MySQL. It was built and measured on
MariaDB 10.4.32 (XAMPP); MySQL has not been tested. Redis is not required.

```bash
composer install
```

```bash
cp .env.example .env
```

```bash
php artisan key:generate
```

Create the database. The default `.env` uses `root` with no password.

```bash
mysql -u root -e "CREATE DATABASE task_mallow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Create the tables and demo data, then start the app:

```bash
php artisan migrate --seed
```

```bash
php artisan serve
```

Open <http://localhost:8000/dashboard> and use a login from [Demo access](#demo-access).

The seeder runs through the real actions, rollup and invoicing, relative to today's
date. It creates 200 customers with heavy, steady, churning, late-joining and upgrading
usage.

### Background processes

Rollups and invoices do not run until these are started, each in its own terminal:

```bash
php artisan queue:work
```

```bash
php artisan schedule:work
```

| Schedule | Command | Purpose |
|---|---|---|
| Hourly | `billing:aggregate` | Rolls up the last 4 days: today plus the late window |
| Daily, 00:30 UTC | `billing:invoice` | Invoices each period that closed at least 3 days ago |

Run `php artisan billing:aggregate --days=90` to backfill rollups.

### Tests

```bash
php artisan test
```

Tests run on in-memory SQLite, so they need no database setup. They do not cover
partitioning or MariaDB's SQL rules; see [Testing gaps](#testing-gaps).

## API

Every request needs an `X-Api-Key` header.

### Record usage

```bash
curl -i -X POST http://localhost:8000/api/usage \
  -H "X-Api-Key: demo-acme-key" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"customer_id":"cust-001","usage_date":"YYYY-MM-DD","quantity":25,"idempotency_key":"evt-1001"}'
```

| Response | Meaning |
|---|---|
| `201 {"status":"recorded"}` | New event stored |
| `200 {"status":"duplicate"}` | Key already seen; nothing changed |
| `401` | Bad or missing API key |
| `422` | Validation failed, or unknown customer |
| `429` | Over the limit, with `Retry-After` |

`customer_id` is the merchant's own `external_id`. `usage_date` must be today or
within the last 3 days (UTC). `quantity` is 1 to 1,000,000.

### Change plan

```bash
curl -i -X POST http://localhost:8000/api/subscriptions/1/change-plan \
  -H "X-Api-Key: demo-acme-key" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"plan_id":3}'
```

`effective_on` is optional and defaults to tomorrow. Returns `200` on success, `404` for
another merchant's subscription, `409` if the subscription is canceled or a change
already exists on or after that date, and `422` for an invalid plan or date.

### Dashboard

```bash
curl -s http://localhost:8000/api/merchants/1/dashboard -H "X-Api-Key: demo-acme-key"
```

Returns the top 5 customers this month, projected overage for the current cycle, and
churn risk. Another merchant's ID returns `404`.

## Assumptions and decisions

The brief left these open. Each is a judgement call rather than a requirement.

### Billing

| Decision | Why |
|---|---|
| Billing is in arrears, base price included | The brief invoices at cycle end, and it means a downgrade never needs a credit |
| Cycles follow the calendar: 1st of the month, or 1 January | Mid-cycle proration only exists if cycles are not anchored to each customer's start date |
| Proration is by days: segment days / cycle days | Usage is recorded per day |
| The allowance is prorated like the base price, rounded down | A half month should not get a full month's allowance |
| Overage is calculated per segment, and unused allowance does not carry over | Each segment bills at its own rate, as the plan-change requirement asks |
| Money rounds half up per line, and totals are the sum of the lines | The invoice always adds up as printed |
| Amounts are integers: minor units, and micros for rates | Avoids float errors, and keeps rates below one paisa exact |
| One billable metric, and one active subscription per customer | The brief describes API calls only |

Splitting a cycle can lose one allowance unit per split, for example 4,838 + 5,161 =
9,999 rather than 10,000. That favours the merchant by at most one unit.

### Plan changes

| Decision | Why |
|---|---|
| Changes start at 00:00 UTC, and tomorrow at the earliest | Usage is per day, so each day belongs to exactly one plan |
| Monthly and yearly plans cannot switch mid-cycle | A cycle prorates against one length; cancel and resubscribe instead |
| A price edit applies from the next period | Open periods use their snapshot |

Usage for the rest of the day a change is requested stays on the old plan.

### Usage ingest

| Decision | Why |
|---|---|
| Idempotency keys are unique per customer per day | Partitioning needs the date in every unique key; a true retry sends the same body |
| A retry with a different quantity keeps the original | The first write wins and returns `duplicate` |
| Events up to 3 days late are accepted; older ones are rejected | Invoicing waits the same 3 days, so no event can arrive after its period is billed |
| Usage is accepted without an active subscription | Checking would add a read to every write; those units never reach an invoice |
| The rate limit is per merchant: 1,000 a minute by default | One merchant may send from many IPs, and several may share one |

### Dashboard

| Decision | Why |
|---|---|
| Projection is actual usage plus the period's daily average for the days left | Simple and explainable, though a spike early in a period inflates it |
| Churn compares this month to the same days last month, up to yesterday | A part month against a whole month would flag almost everyone |
| A drop of exactly 50% is not flagged | The brief says more than 50% |
| Results are cached for 5 minutes | Data already moves hourly, and a cold load is expensive |

## Trade-offs made under time pressure

- **No buffered ingest.** `POST /usage` inserts directly. It is simple and durable but
  limited by commit speed. See the [measured limits](#measured-limits).
- **Per-merchant API keys only.** There is no user login, key rotation endpoint or
  rate limit on failed authentication.
- **No CRUD for merchants, plans or customers.** `SubscribeCustomer` and
  `CancelSubscription` exist as tested actions without endpoints, because the brief does
  not ask for them. Data comes from the seeder.
- **Invoices are issued immediately.** There is no review step, payment or tax.
- **Partitions are pre-created for 24 months,** with a `pmax` catch-all. Production
  needs a scheduled job to add new months.

## Known limitations

- **Plan changes are blocked for about 3 days after a period ends.** Between a period's
  end and its invoice, no date is valid. Fixing it needs a way to queue a change for the
  next period.
- **`subscriptions.plan_id` updates when a change is requested,** not when it takes
  effect. Billing follows the segment dates, so invoices are right, but a screen showing
  "current plan" from that column would be early.
- **The projection during that same window** shows the closing cycle's overage, not a
  forecast for the new one.
- **Catching up after an outage** bills one period per sweep, per subscription.
- **Bulk query-builder updates** skip model events, so a plan change made that way can
  stay cached for up to an hour. Billing is unaffected, because it uses segment
  snapshots.
- **Dashboard figures lag** by up to about an hour of rollup plus 5 minutes of cache.

## Measured limits

Measured on a laptop with MariaDB's XAMPP defaults, including a 16 MB buffer pool. These
are indicative, not capacity claims. Full results are in
[docs/load-test.md](docs/load-test.md).

| At 50L rows | Result |
|---|---|
| One customer, one month of raw events | 4.4 ms |
| Single insert | 3.2 ms |
| 90-day rollup through a real worker | 54 s |
| Dashboard, cold / cached | 1.43 s / 2.4 ms |
| 8 parallel writers racing on 300 shared keys | Exactly 1,100 rows from 3,200 requests |

The weak spot is random-order ingest: bulk inserts fell to about 1,750 rows a second by
5L rows, once the unique index outgrew the buffer pool. The 50L set was loaded in sorted
order to finish in minutes, so it does not represent live traffic. A full request through
the framework took about 8.5 ms in single-process tests here.

### Testing gaps

SQLite accepted a query that MariaDB rejected, which only surfaced in a manual run.
Partitioning, pruning and concurrency were checked by hand on MariaDB using throwaway
scripts that are not in this repo. `billing:load-test` is included, so the data set can
be rebuilt. The hourly schedule was registered but never left running; the jobs were
started by command and a real `queue:work`.

## What I would do with more time

1. **Run the test suite on MariaDB in CI,** to catch what SQLite cannot.
2. **Size the buffer pool and repeat the random-order load test,** to find the real limit.
3. **Buffer writes through Redis or Kafka,** with bulk inserts, if that limit is too low.
4. **Move the rate limiter and cache to Redis,** so limits are shared across servers.
5. **Store a running total per segment,** so the dashboard does not recompute every
   subscription. Cold load time grows with subscription count.
6. **Queue plan changes for the next period,** which removes the 3-day block.
7. **Add a partition maintenance job,** and archive old raw events to cold storage.
8. **Support multiple metrics,** with a `metric` column and per-metric allowances.
