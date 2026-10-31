# Ledgerline Observability: notes for contributors and coding agents

A Telescope/Pulse-style performance dashboard (Laravel 13, PHP 8.4, Vue 3 + Vue Router, Tailwind 4, hand-built SVG
charts) that instruments Ledgerline, a demo invoicing app in the same codebase. `README.md` explains the design. Read it
before changing recording, sampling, rollups or reports.

## Commands

```sh
php artisan migrate:fresh --seed                  # Ledgerline data + a simulated week of telemetry (~45s)
php artisan test --exclude-group slow             # PHPUnit on in-memory SQLite, without the simulator tests
vendor/bin/pint --test                            # the style check CI runs
npm run build                                     # production assets
E2E_URL=http://127.0.0.1:8050 npm run test:e2e    # browser smoke test; needs a running, seeded app
```

## Invariants to keep

- **Telemetry fails open and never observes itself.** Writes go through `Recorder::persist()`, which logs and swallows
  errors and sets `$persisting` so the recorder's own queries and exceptions are ignored. Persisting happens in
  `RecordRequest::terminate()` (prepended, so it times the whole stack). Only `observability.record_paths`
  (`ledgerline*`) are recorded, never the dashboard.
- **Rollups count 100% of traffic; only full traces are sampled.** `TraceWriter::sampleReason()` decides after the
  work finishes, then `withinBudget()` caps kept traces per route (or job class) per minute. Exceptions have their own
  per-group budget but always count in the group and its rollup. A rollup must never depend on whether a trace was kept.
- **Rollups are additive and written as one upsert.** Feed them through `RollupBuffer` (every observation lands in the
  60s and 3600s periods) and write with `RollupWriter`: `col = col + incoming` for sums, `greatest`/`max` for maxima,
  no read-modify-write. A new column must be a sum or a max, be listed in `RollupBuffer::COLUMNS` and work on every
  driver (`RollupWriter::incoming()` covers `excluded.`, MySQL `values()` and the `DB_USE_UPSERT_ALIAS` alias).
- **Percentiles come from summed histograms, never from averaged percentiles.** `Histogram` stores 51 fixed log buckets
  as `h0..h50`. `Reports\Rollups::aggregate()` sums them in SQL, and PHP only interpolates. Changing `GROWTH` or
  `BUCKETS` invalidates every stored row.
- **Series stay low-cardinality and PII-free.** Requests are keyed by route pattern or `METHOD [unmatched]`, never the
  URL. Queries are keyed by their `SqlNormalizer` fingerprint, and bindings are never stored. Rollups and N+1 detection
  read `Trace::$shapes`. `$queries` is capped at `MAX_STORED_QUERIES` and only feeds the waterfall.
- **Exception groups are class + first app frame's file and function** (`ExceptionGrouper::fingerprint()`), not
  message or line. A recurrence clears `resolved_at`.
- **Sync-driver jobs belong to their request.** `Recorder::startJob()` refuses while a request trace is open. Queue
  wait comes from the `obs_queued_at` payload stamp and is measured on attempt 1 only.
- **Resolution follows the range.** `TimeRange::period()` reads minute rollups for steps under an hour, hour rollups
  otherwise. Minute rows expire after `retention.minute_rollups_hours`, so `DeployReport` and the simulator fall back
  to hour rows (deploy windows snap inward and never cross a neighbouring deploy).
- **The simulator must match the live app.** `LedgerlineSimulator` writes through the same buffer and writer and
  hard-codes Ledgerline's SQL and `file:line` locations in `defineTemplates()`. When you change a Ledgerline query,
  update its template, or history and live traffic split into separate fingerprints (a moved line leaves a stale
  location). Keep it deterministic: draw only from its seeded `Randomizer` and `$now`, never `mt_rand()` or `time()`.

## Conventions

- Dashboard endpoints go in the `auth` group in `routes/web.php` (session + CSRF) and take a `RangeRequest`. Report
  logic belongs in `app/Observability/Reports/*`. `/ledgerline/*` is unauthenticated on purpose: it is demo workload.
- Tests set `observability.sample_rate` to 0 and use the `array` budget store when they count kept traces. Simulate a
  telemetry outage with `DB::connection()->beforeExecuting()`, not by dropping a table, which commits implicitly on
  MySQL (see `RequestInstrumentationTest`).
