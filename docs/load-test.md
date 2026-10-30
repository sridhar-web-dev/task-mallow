# Load test at 50L rows

Measured on 2026-10-30 on a Windows laptop, using XAMPP's MariaDB 10.4.32 with its
defaults: a **16 MB buffer pool** and a disk flush on every commit. A production
server would have a far larger buffer pool, so treat these as indicative figures
from a modest setup, not as a capacity claim.

## Data set

`php artisan billing:load-test --rows=5000000`

| Measure | Result |
|---|---|
| Rows in `usage_events` | 50,11,220 |
| Spread | 2,000 customers over 90 days, in 3 partitions |
| Table data / indexes | 468 MB / 621 MB, about 230 bytes per row |
| Rows in `daily_usage` | 1,89,792, about 1/26 of the raw table |

## Reads and writes

| Operation | Median |
|---|---|
| One customer, one day of raw events | 1.7 ms |
| One customer, this month (1 partition) | 4.4 ms |
| One customer, no date filter (25 partitions) | 10.1 ms |
| Rollup query for 500 customers over 4 days | 607 ms |
| Full 90-day rollup: 5 chunks through a real worker | 54 s |
| New event, single insert | 3.2 ms |
| Duplicate event, ignored by the unique index | 3.2 ms |
| Dashboard, cold | 1.43 s |
| Dashboard, cached | 2.4 ms |

`EXPLAIN PARTITIONS` confirms pruning: a query with a date range reads only
`p202610`, against all 25 partitions without one. Insert cost barely grew with the
table: about 3.2 ms at 50L against 2.6 ms on a nearly empty one.

## Concurrency

8 processes sent `POST /usage` in-process at the same time for one customer and
day: 300 keys shared by all 8, shuffled, plus 100 keys of their own.

| Check | Expected | Result |
|---|---|---|
| Rows with shared keys | 300 | 300 |
| Rows with own keys | 800 | 800 |
| `201` responses across all workers | 1,100 | 1,100 |
| Total requests | 3,200 | 3,200, about 220 a second |

Each shared key was inserted exactly once, even when several workers raced on it.
The workers bootstrap the application once and do not go through a web server, so
this shows idempotency under concurrency, not real HTTP capacity.

## Ingest rate

Two different numbers, for two different arrival patterns:

| Row order | Rate |
|---|---|
| Sorted by day and customer, in 2,000-row batches (how the 50L were loaded) | 16,800 rows/s |
| Random customers, in 2,000-row batches (like live traffic) | Fell to about 1,750 rows/s by 5L |

The random case is the realistic one. Random writes across a unique index slow down
once the index no longer fits in the buffer pool, here 16 MB. Sorted loading was used
to finish in minutes without changing server settings, and it says nothing about
live ingest.

## What this changed

The first run found two real problems in the dashboard, both fixed:

1. The projection query scanned the entire covering index. Adding `merchant_id`
   and a date range turned it into a range scan.
2. Every subscription read its plan from the `file` cache several times. Remembering
   plans within a run took out thousands of reads.

Together these took a cold dashboard from 2.3 s to 1.43 s.

## What is left

- About 1 s of a cold dashboard is PHP projecting 2,000 subscriptions. It scales
  with subscriptions, so a merchant with 2 lakh would need a stored running total
  per segment instead of recomputing it. The 5-minute cache hides this for now.
- Sustained random ingest needs a larger buffer pool first, then buffered bulk
  writes through Redis or Kafka.
- SQLite tests cannot exercise partitions or MariaDB's SQL rules. A CI job running
  the suite on MariaDB would catch what was found here by hand.
