# Ledgerline Observability

A performance and reliability dashboard for a Laravel app, similar to Telescope, Horizon and Pulse. The instrumentation layer
captures every request, query, exception and queued job. It keeps full traces with **tail-based sampling** and stores
metrics in **histogram rollups**. These rollups support percentiles, SLO error budgets and before/after comparisons for each deploy.

The monitored app is **Ledgerline**, a small invoicing app in the same codebase. It has real controllers, a real N+1
toggle, a real legacy-data bug and real queued jobs. A seeded simulator adds a week of history: deploys, an N+1
regression and its fix, an index fix, and an SMTP incident that backs up the mail queue.

**Stack:** Laravel 13 · PHP 8.4 · Vue 3 + Vue Router · Tailwind 4 · SQLite or MySQL (portable SQL) · hand-built SVG charts

## Quick start

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed      # ~45s: Ledgerline data + a simulated week of telemetry
npm run build
php artisan serve --port=8050         # sign in as admin@example.com / password
```

For live data, run these alongside:

```bash
php artisan queue:work --queue=mail,pdf,webhooks   # instrumented job runs
php artisan schedule:work                          # queue depth snapshots (1/min) and pruning (hourly)
php artisan obs:traffic --minutes=5 --rpm=120      # real HTTP traffic against /ledgerline/*
```

To reproduce the N+1 regression on live traffic, set `LEDGERLINE_EAGER_LOAD=false`. To stamp a release, set
`APP_RELEASE=v3.16.0`, then run `php artisan obs:deploy v3.16.0`.

The Ledgerline routes under `/ledgerline/*` are a demo workload and have no authentication on purpose, so
`obs:traffic` can drive them. In particular, `POST /ledgerline/webhooks/payments` does not verify a gateway signature.
Don't expose them on a public host. The dashboard API (`/api/admin/*`) requires a session login.

## How it works

| Piece | Where | What it does |
|---|---|---|
| Recorder | `app/Observability/Recorder.php` | Holds the current request or job in memory. Persists it in `terminate()`, after the response is sent. Fails open and ignores its own queries. |
| Query capture | `SqlNormalizer`, `Trace` | Fingerprints SQL: literals become `?` and IN-lists collapse. Bindings are never stored. Records the app file:line that issued each query. Flags N+1 when one SELECT shape runs 5+ times. |
| Tail sampling | `TraceWriter::sampleReason()` | Keeps every error, exception, slow request, slow query and N+1. Keeps 10% of everything else. |
| Rollups | `RollupBuffer`, `RollupWriter` | Minute and hour rows for requests, queries, jobs and exceptions. A latency histogram is stored as 51 integer columns, so writes are one atomic `INSERT … ON CONFLICT DO UPDATE SET h7 = h7 + excluded.h7`, with no locks or read-modify-write. |
| Percentiles | `Histogram` | Log-scale buckets that grow ×1.25 each. Buckets are summed in SQL, then interpolated geometrically. Also gives the "fraction under X ms" figure that latency SLOs need. |
| Exceptions | `ExceptionGrouper` | Groups by class and first app frame (not by message). Credentials are scrubbed. Stores at most 20 occurrences per group per minute and counts all of them. A resolved group reopens if it recurs. |
| Jobs | `ObservabilityServiceProvider` | Queue events plus an enqueue timestamp in the payload give wait time, runtime and processed/released/failed status. |
| Reports | `app/Observability/Reports/*` | Overview, routes, traces, queries, exceptions, queues, deploy comparisons and SLOs with multi-window burn rates. |

Artisan commands: `obs:simulate`, `obs:traffic`, `obs:deploy`, `obs:snapshot-queues`, `obs:prune`.

## Tests

```bash
php artisan test                                  # PHPUnit
E2E_URL=http://127.0.0.1:8050 npm run test:e2e    # browser smoke test (needs a running, seeded app and Edge/Chrome)
```

Verification status, build notes and to-dos live in a local `Project_Summary.md`, which is kept out of git.
