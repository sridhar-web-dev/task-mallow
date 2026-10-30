<?php

return [

    'usage' => [
        'rate_limit_per_minute' => (int) env('USAGE_RATE_LIMIT_PER_MINUTE', 1000),

        // How many days after a usage date an event is still accepted. Invoicing
        // waits this long after a cycle ends, so no event can arrive once its
        // period has been billed.
        'late_window_days' => (int) env('USAGE_LATE_WINDOW_DAYS', 3),

        'max_quantity' => 1_000_000,
    ],

    // Customers or subscriptions per queued job.
    'chunk_size' => (int) env('BILLING_CHUNK_SIZE', 500),

    'cache' => [
        // A safety net for writes that bypass model events; observers clear entries sooner.
        'ttl_seconds' => (int) env('BILLING_CACHE_TTL_SECONDS', 3600),

        'dashboard_ttl_seconds' => (int) env('DASHBOARD_CACHE_TTL_SECONDS', 300),
    ],

];
