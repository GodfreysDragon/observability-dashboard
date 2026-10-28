<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Recording
    |--------------------------------------------------------------------------
    |
    | Master switch for the instrumentation layer. When disabled, no listeners
    | are registered and the request middleware is a pass-through.
    |
    */

    'enabled' => env('OBSERVABILITY_ENABLED', true),

    /*
    | The release tag stamped on every trace, exception and job run. CI sets it
    | per deploy (e.g. the git tag) and registers the deploy with obs:deploy.
    */

    'release' => env('APP_RELEASE', 'dev'),

    /*
    | Paths that are never recorded: the dashboard itself, its API, health checks
    | and build assets. The monitor should not measure its own reads.
    */

    'ignore_paths' => [
        'api/admin*',
        'up',
        'build/*',
        'favicon.ico',
    ],

    /*
    | Only requests under these paths are recorded. The dashboard SPA is served
    | from every other path, so it is excluded by default. Use ['*'] in an app
    | that only hosts the monitored application.
    */

    'record_paths' => ['ledgerline*'],

    /*
    |--------------------------------------------------------------------------
    | Tail-based sampling
    |--------------------------------------------------------------------------
    |
    | Rollups always count 100% of traffic. Full traces (the request plus its
    | queries) are kept when the outcome is interesting, decided after the
    | request finishes: a 5xx, an exception, a slow request, a slow query or a
    | suspected N+1. Everything else is kept at `sample_rate`.
    |
    */

    'sample_rate' => (float) env('OBSERVABILITY_SAMPLE_RATE', 0.1),

    'slow_request_ms' => 1000,

    'slow_query_ms' => 100,

    'slow_job_ms' => 5000,

    /*
    | A SELECT with the same fingerprint executed at least this many times in
    | one request or job is flagged as a likely N+1.
    */

    'n_plus_one_threshold' => 5,

    /*
    | Capture the app file:line that issued each query (skips vendor frames).
    | Costs a short debug_backtrace() per query.
    */

    'query_locations' => true,

    /*
    | At most this many traces per exception group per minute are stored as
    | occurrences. Every exception is still counted in the rollups, so a tight
    | error loop can't flood the table.
    */

    'exception_occurrences_per_minute' => 20,

    /*
    |--------------------------------------------------------------------------
    | Service level objectives
    |--------------------------------------------------------------------------
    */

    'apdex_t_ms' => 300,

    'slo' => [
        'availability' => 0.995,
        'latency_ms' => 1000,
        'latency_target' => 0.97,
        'window_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention (obs:prune)
    |--------------------------------------------------------------------------
    |
    | Minute rollups back the short ranges (up to 24h, plus the previous 24h for
    | comparison). Hour rollups back the 7-day views and deploy comparisons.
    |
    */

    'retention' => [
        'minute_rollups_hours' => 50,
        'hour_rollups_days' => 30,
        'traces_hours' => 72,
        'snapshots_days' => 8,
        'exceptions_days' => 30,
    ],

    /*
    | Queues that obs:snapshot-queues samples for depth and oldest-job age.
    */

    'queues' => ['mail', 'pdf', 'webhooks'],

];
