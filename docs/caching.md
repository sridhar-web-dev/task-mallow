# Caching

## What is cached

| Data | Service | Key | Why |
|---|---|---|---|
| A plan's pricing | `PlanCatalog` | `plan:{id}` | Read when opening segments, billing and projecting overage |
| Merchant id for an API key | `TenantDirectory` | `merchant-api-key:{hash}` | Read on every `POST /usage` |
| Customer id for an external id | `TenantDirectory` | `merchant:{id}:customer:{hash}` | Read on every `POST /usage` |

API keys are never used as cache keys in plaintext. `PlanPricing` is a plain
value object, so no Eloquent model is ever serialized into the cache.

## Invalidation

Entries are cleared by observers and expire by TTL as a safety net.

1. **Observers** on `Plan`, `Merchant` and `Customer` clear the affected keys on
   `saved` and `deleted`.
2. **After commit.** Observers run only once the transaction commits. If they ran
   earlier, a reader could reload the old row into the cache just before the
   change landed, and that stale value would then live for the full TTL. A
   rollback clears nothing, because nothing changed.
3. **Previous values.** Rotating an API key or changing an `external_id` clears
   the old key as well, so a rotated key stops working immediately.
4. **TTL (1 hour, `BILLING_CACHE_TTL_SECONDS`).** Query-builder bulk updates fire
   no model events, so they are not seen by observers. The TTL limits how long
   such a change can stay stale.

Only hits are cached in `TenantDirectory`. A new customer works immediately,
rather than being remembered as missing.

### Remaining stale window

A reader that misses the cache, reads the old row, and writes it back after the
observer has cleared the key can leave a stale entry until the TTL expires. The
window is a few milliseconds, and a lock on every miss is not worth it for
data that changes this rarely.

## Why a stale plan price cannot affect billing

A plan edit changes the price of **future** billing periods only. Billing reads
the pricing snapshot on `subscription_segments`, so neither a stale cache entry
nor a plan edit can change an open period or an issued invoice.

## Stores

| Environment | Store | Notes |
|---|---|---|
| Tests | `array` | Set in `phpunit.xml` |
| Local | `file` | Needs no extra service, single server only |
| Production | `redis` | Shared across app servers, and suits the rate limiter |

`CACHE_STORE=database` also works, but the rate limiter then writes to the
`cache` table on every request, on the same database that takes the usage writes.
