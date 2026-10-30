# Schema and scale notes

## Tables

| Table | Purpose | Expected size |
|---|---|---|
| `merchants` | Tenants, with a hashed API key | Small |
| `plans` | A merchant's pricing | Small |
| `customers` | A merchant's customers, keyed by `external_id` | Medium |
| `subscriptions` | One customer on one plan, with the current period | Medium |
| `subscription_segments` | Stretches of a period on one plan, with a pricing snapshot | Medium |
| `usage_events` | Raw, append-only usage | **50L+ and growing** |
| `daily_usage` | Rollup: one row per customer per day | Customers x days |
| `invoices`, `invoice_lines` | Immutable billing output | Medium |

Money is stored as integers. Amounts use minor units (paise). Overage rates use
micros, millionths of the major unit, so a rate of ₹0.0025 per call is exact.

Date ranges are half-open: `[start, end)`.

## How `usage_events` holds up at 50L+

Writes and reads are kept apart. `POST /usage` only appends here, and billing and
the dashboard read `daily_usage`, so no user-facing query scans raw events.

- **One secondary index.** `UNIQUE (customer_id, usage_date, idempotency_key)`
  rejects retries, and its prefix serves the aggregator's lookups by customer and
  day. Each extra index would slow every insert.
- **Small keys.** `idempotency_key` is ASCII, so it takes 64 bytes in the index
  rather than the 256 that `utf8mb4` would reserve.
- **Monthly `RANGE` partitions on `usage_date`.** A query that filters on a date
  touches only the relevant months. Old data leaves through `DROP PARTITION`,
  which is near-instant, where `DELETE` would take a long time and churn the table.
- **No foreign keys.** MariaDB and MySQL do not allow them on partitioned tables.
  This also removes a parent lookup from every insert. `POST /usage` validates the
  customer before writing.

### What partitioning costs

- Every unique key must contain `usage_date`, so the primary key is
  `(id, usage_date)` and the idempotency key is unique per customer **per day**.
  A retry that changes `usage_date` counts as a new event. A true retry sends the
  same body, so this is an accepted trade-off.
- The migration creates 24 months from January 2026, plus a `pmax` catch-all so an
  insert can never fail. Production would need a scheduled job that adds new months.

SQLite has no partitioning, so the test database gets the same columns and unique
key without it.

## Denormalization

- `merchant_id` sits on `subscriptions`, `usage_events`, `daily_usage` and
  `invoices`, so tenant queries avoid a join through `customers`.
- `daily_usage` is itself denormalized: it is derived from `usage_events` and can
  be rebuilt from it.
- `subscription_segments` and `invoice_lines` copy pricing on purpose, so editing a
  plan never changes an open period or an issued invoice.
- `daily_usage_dashboard_index` includes `units`, which makes it a covering index:
  dashboard totals are answered from the index alone.

## What I would add at higher volume

- Monthly rollups, if merchants grow large enough to make summing 60 days per
  customer slow on the dashboard.
- Buffering writes in Redis or Kafka and bulk-inserting them, if direct inserts
  become the bottleneck.
- Moving old raw events to cold storage once their billing periods are closed.
- A columnar store such as ClickHouse for analytics, if merchants want more than
  this rollup can answer.
