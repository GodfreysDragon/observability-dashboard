<?php

namespace App\Observability\Simulation;

use App\Observability\ExceptionGrouper;
use App\Observability\RollupBuffer;
use App\Observability\RollupWriter;
use App\Observability\SqlNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfills a week of believable telemetry for Ledgerline, through the same RollupBuffer
 * and RollupWriter the live recorder uses, with the same SQL fingerprints, route names
 * and exception fingerprints the live app produces, so live traffic lands on the same
 * series as the history.
 *
 * The week has a story a reviewer can find in the dashboard:
 *  - v3.14.1 adds an index: the aging report's p95 drops from ~2.4s to ~650ms;
 *  - v3.15.0 drops eager loading on the invoice list (N+1: 4 -> 52 queries per request)
 *    and ships a VAT refactor that throws for legacy "XI" accounts, which stays unresolved;
 *  - v3.15.1 restores eager loading;
 *  - nine hours ago a statement run met a degraded SMTP relay: mail jobs slow from ~250ms
 *    to ~7s, a third of them fail and the mail queue backs up for most of an hour.
 *
 * Deterministic for a given seed and anchor time.
 */
final class LedgerlineSimulator
{
    /** Kept-trace sampling. Interesting traces are always kept, except N+1 traces (see below). */
    private const float SAMPLE_RATE = 0.01;

    /**
     * The live recorder keeps every N+1 trace. Backfilling 30 hours of them would add
     * ~600k query rows, so the simulator keeps one in ten; rollups still count them all.
     */
    private const float N_PLUS_ONE_KEEP = 0.1;

    private const int PEAK_RPM = 75;

    /** @var array<string, array{sql: string, fingerprint: string, location: ?string}> */
    private array $templates = [];

    /** @var array<string, int> release => deployed at */
    private array $deploys = [];

    /** @var array<string, array{id: int, count: int, first: ?int, last: ?int, release: ?string, message: string, trace: string}> */
    private array $groups = [];

    /** @var array<string, list<array{at: int, attempt: int, count: int}>> */
    private array $queues = [];

    /** @var array<string, list<array<string, mixed>>> raw rows waiting for insert */
    private array $raw = [];

    private int $nextRequestId = 1;

    private int $nextJobRunId = 1;

    private int $now;

    private int $start;

    private int $incidentStart;

    private int $incidentEnd;

    private int $minuteRetainedFrom;

    /** @var array<string, int> */
    private array $stats = ['requests' => 0, 'jobs' => 0, 'kept_requests' => 0, 'kept_jobs' => 0, 'queries' => 0];

    public function __construct(private readonly RollupWriter $writer) {}

    /**
     * @param  (callable(int $done, int $total): void)|null  $progress
     * @return array<string, int>
     */
    public function run(int $days = 7, int $seed = 7, ?int $now = null, ?callable $progress = null): array
    {
        mt_srand($seed);

        $this->now = intdiv($now ?? time(), 60) * 60;
        $this->start = $this->now - $days * 86400;
        $this->minuteRetainedFrom = $this->now - (config('observability.retention.minute_rollups_hours') - 1) * 3600;
        $this->incidentStart = $this->now - 9 * 3600;
        $this->incidentEnd = $this->incidentStart + 45 * 60;

        $this->wipe();
        $this->defineTemplates();
        $this->defineDeploys();
        $this->defineExceptionGroups();
        $this->queues = ['mail' => [], 'pdf' => [], 'webhooks' => []];

        $hours = intdiv($this->now - $this->start, 3600);
        $buffer = $this->bufferFor($this->start);

        for ($minute = $this->start; $minute < $this->now; $minute += 60) {
            $arrivals = $this->simulateRequests($minute, $buffer);
            $this->simulateQueues($minute, $arrivals, $buffer);

            if (($minute + 60) % 3600 === 0 || $minute + 60 >= $this->now) {
                $this->writer->write($buffer);
                $this->flushRaw();
                $buffer = $this->bufferFor($minute + 60);

                if ($progress) {
                    $progress(min($hours, intdiv($minute + 60 - $this->start, 3600)), $hours);
                }
            }
        }

        $this->flushRaw(force: true);
        $this->finishExceptionGroups();

        return $this->stats;
    }

    private function bufferFor(int $timestamp): RollupBuffer
    {
        return new RollupBuffer(
            config('observability.apdex_t_ms'),
            $timestamp >= $this->minuteRetainedFrom ? [60, 3600] : [3600],
        );
    }

    private function wipe(): void
    {
        foreach ([
            'obs_exceptions', 'obs_queries', 'obs_requests', 'obs_job_runs', 'obs_exception_groups',
            'obs_request_rollups', 'obs_query_rollups', 'obs_job_rollups', 'obs_exception_rollups',
            'obs_query_fingerprints', 'obs_queue_snapshots', 'obs_deploys',
        ] as $table) {
            DB::table($table)->delete();
        }
    }

    private function defineTemplates(): void
    {
        $invoices = 'app/Http/Controllers/Ledgerline/InvoiceController.php';
        $customers = 'app/Http/Controllers/Ledgerline/CustomerController.php';
        $webhook = 'app/Http/Controllers/Ledgerline/PaymentWebhookController.php';

        $templates = [
            'inv_count' => ['select count(*) as "aggregate" from "invoices"', "{$invoices}:22"],
            'inv_page' => ['select * from "invoices" order by "issued_on" desc, "id" desc limit ? offset ?', "{$invoices}:22"],
            'cust_in' => ['select * from "customers" where "customers"."id" in (...)', "{$invoices}:22"],
            'lines_in' => ['select * from "invoice_lines" where "invoice_lines"."invoice_id" in (...)', "{$invoices}:22"],
            'cust_eq' => ['select * from "customers" where "customers"."id" = ? limit ?', "{$invoices}:27"],
            'lines_eq' => ['select * from "invoice_lines" where "invoice_lines"."invoice_id" = ? and "invoice_lines"."invoice_id" is not null', "{$invoices}:29"],
            'inv_by_number' => ['select * from "invoices" where "number" = ? limit ?', null],
            'pay_in' => ['select * from "payments" where "payments"."invoice_id" in (...)', "{$invoices}:39"],
            'jobs_insert' => ['insert into "jobs" ("queue", "attempts", "reserved_at", "available_at", "created_at", "payload") values (?, ?, ?, ?, ?, ?)', "{$invoices}:57"],
            'cust_by_id' => ['select * from "customers" where "id" = ? limit ?', null],
            'cust_invoices_sum' => ['select "invoices".*, (select sum("payments"."amount_cents") from "payments" where "invoices"."id" = "payments"."invoice_id") as "payments_sum_amount_cents" from "invoices" where "invoices"."customer_id" = ? and "invoices"."customer_id" is not null order by "issued_on" desc limit ?', "{$customers}:15"],
            'search_cust' => ['select "name" from "customers" where "name" like ? limit ?', "{$customers}:31"],
            'search_inv' => ['select "number" from "invoices" where "number" like ? limit ?', "{$customers}:32"],
            'aging' => ['select customers.name AS customer, SUM(CASE WHEN invoices.due_on >= ? THEN invoices.total_cents - COALESCE(paid.paid, ?) ELSE ? END) AS current_cents, SUM(CASE WHEN invoices.due_on < ? AND invoices.due_on >= ? THEN invoices.total_cents - COALESCE(paid.paid, ?) ELSE ? END) AS d1_30, SUM(CASE WHEN invoices.due_on < ? AND invoices.due_on >= ? THEN invoices.total_cents - COALESCE(paid.paid, ?) ELSE ? END) AS d31_60, SUM(CASE WHEN invoices.due_on < ? AND invoices.due_on >= ? THEN invoices.total_cents - COALESCE(paid.paid, ?) ELSE ? END) AS d61_90, SUM(CASE WHEN invoices.due_on < ? THEN invoices.total_cents - COALESCE(paid.paid, ?) ELSE ? END) AS d90_plus from "invoices" inner join "customers" on "customers"."id" = "invoices"."customer_id" left join (select "invoice_id", SUM(amount_cents) AS paid from "payments" group by "invoice_id") as "paid" on "paid"."invoice_id" = "invoices"."id" where "invoices"."status" != ? group by "customers"."id", "customers"."name" order by "d90_plus" desc limit ?', 'app/Http/Controllers/Ledgerline/ReportController.php:38'],
            'webhook_exists' => ['select count(*) as "aggregate" from "invoices" where "number" = ?', "{$webhook}:21"],
            'inv_lock' => ['select * from "invoices" where "number" = ? limit ?', "{$webhook}:28"],
            'pay_by_ref' => ['select * from "payments" where ("gateway_ref" = ?) limit ?', "{$webhook}:30"],
            'pay_insert' => ['insert into "payments" ("gateway_ref", "invoice_id", "amount_cents", "paid_at") values (?, ?, ?, ?)', "{$webhook}:30"],
            'pay_sum' => ['select sum("amount_cents") as "aggregate" from "payments" where "payments"."invoice_id" = ? and "payments"."invoice_id" is not null', "{$webhook}:35"],
            'inv_by_id' => ['select * from "invoices" where "invoices"."id" = ? limit ?', null],
            'inv_update_sent' => ['update "invoices" set "sent_at" = ?, "updated_at" = ? where "id" = ?', 'app/Jobs/SendInvoiceEmail.php:36'],
            'pay_by_id' => ['select * from "payments" where "payments"."id" = ? limit ?', null],
            'pay_update_synced' => ['update "payments" set "synced_at" = ? where "id" = ?', 'app/Jobs/SyncPaymentToLedger.php:30'],
            'jobs_delete' => ['delete from "jobs" where "id" = ?', null],
        ];

        $rows = [];

        foreach ($templates as $key => [$sql, $location]) {
            $normalized = SqlNormalizer::normalize($sql);
            $fingerprint = SqlNormalizer::fingerprint($normalized);
            $this->templates[$key] = ['sql' => $normalized, 'fingerprint' => $fingerprint, 'location' => $location];
            $rows[$fingerprint] = ['fingerprint' => $fingerprint, 'sql' => $normalized, 'connection' => DB::getDefaultConnection(), 'first_seen_at' => $this->time($this->start)];
        }

        DB::table('obs_query_fingerprints')->insertOrIgnore(array_values($rows));
    }

    private function defineDeploys(): void
    {
        $deploys = [
            ['v3.14.0', 158, 'a41c9e2', 'ci-bot', 'Upgrade to Laravel 13 and PHP 8.4. No functional changes.'],
            ['v3.14.1', 110, '7f03b18', 'priya', 'Add (status, due_on) index used by the receivables aging report.'],
            ['v3.15.0', 52, 'c95e6d0', 'marco', 'Invoice list: show line count. Move VAT lookup into InvoiceRenderer.'],
            ['v3.15.1', 21, '3be2f47', 'marco', 'Hotfix: restore eager loading on the invoice list.'],
        ];

        foreach ($deploys as [$release, $hoursAgo, $commit, $by, $notes]) {
            $at = $this->now - $hoursAgo * 3600 + 17 * 60;
            $this->deploys[$release] = $at;

            DB::table('obs_deploys')->insert([
                'release' => $release,
                'commit' => $commit,
                'deployed_by' => $by,
                'notes' => $notes,
                'deployed_at' => gmdate('Y-m-d H:i:s', $at),
            ]);
        }
    }

    private function releaseAt(int $timestamp): string
    {
        $current = 'v3.13.4';

        foreach ($this->deploys as $release => $at) {
            if ($timestamp >= $at) {
                $current = $release;
            }
        }

        return $current;
    }

    private function after(string $release, int $timestamp): bool
    {
        return $timestamp >= $this->deploys[$release];
    }

    private function defineExceptionGroups(): void
    {
        $definitions = [
            'vat' => [
                'class' => 'ErrorException',
                'message' => 'Undefined array key "XI"',
                'file' => 'app/Ledgerline/InvoiceRenderer.php',
                'line' => 21,
                'function' => 'App\Ledgerline\InvoiceRenderer->render',
                'frames' => [
                    ['app/Ledgerline/InvoiceRenderer.php', 21, 'throw ErrorException', true],
                    ['vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap/HandleExceptions.php', 258, 'Illuminate\Foundation\Bootstrap\HandleExceptions->handleError()', false],
                    ['app/Ledgerline/InvoiceRenderer.php', 21, 'App\Ledgerline\InvoiceRenderer->render()', true],
                    ['app/Http/Controllers/Ledgerline/InvoiceController.php', 52, 'App\Http\Controllers\Ledgerline\InvoiceController->pdf()', true],
                    ['vendor/laravel/framework/src/Illuminate/Routing/ControllerDispatcher.php', 46, 'Illuminate\Routing\ControllerDispatcher->dispatch()', false],
                    ['vendor/laravel/framework/src/Illuminate/Routing/Route.php', 269, 'Illuminate\Routing\Route->runController()', false],
                ],
            ],
            'deadlock' => [
                'class' => 'Illuminate\Database\QueryException',
                'message' => 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction (Connection: mysql, SQL: select * from `invoices` where `number` = INV-104211 limit 1 for update)',
                'file' => 'app/Http/Controllers/Ledgerline/PaymentWebhookController.php',
                'line' => 28,
                'function' => 'App\Http\Controllers\Ledgerline\PaymentWebhookController->{closure}',
                'frames' => [
                    ['vendor/laravel/framework/src/Illuminate/Database/Connection.php', 838, 'throw QueryException', false],
                    ['vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php', 760, 'Illuminate\Database\Eloquent\Builder->firstOrFail()', false],
                    ['app/Http/Controllers/Ledgerline/PaymentWebhookController.php', 28, 'App\Http\Controllers\Ledgerline\PaymentWebhookController->{closure}()', true],
                    ['vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php', 37, 'Illuminate\Database\Connection->transaction()', false],
                    ['app/Http/Controllers/Ledgerline/PaymentWebhookController.php', 27, 'App\Http\Controllers\Ledgerline\PaymentWebhookController->__invoke()', true],
                ],
            ],
            'smtp' => [
                'class' => 'RuntimeException',
                'message' => 'SMTP relay refused connection: 421 4.7.0 Try again later',
                'file' => 'app/Jobs/SendInvoiceEmail.php',
                'line' => 33,
                'function' => 'App\Jobs\SendInvoiceEmail->handle',
                'frames' => [
                    ['app/Jobs/SendInvoiceEmail.php', 33, 'throw RuntimeException', true],
                    ['vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php', 36, 'App\Jobs\SendInvoiceEmail->handle()', false],
                    ['vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php', 132, 'Illuminate\Container\Container->call()', false],
                    ['vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php', 134, 'Illuminate\Bus\Dispatcher->dispatchNow()', false],
                    ['vendor/laravel/framework/src/Illuminate/Queue/Worker.php', 487, 'Illuminate\Queue\Jobs\Job->fire()', false],
                ],
            ],
        ];

        foreach ($definitions as $key => $definition) {
            $id = DB::table('obs_exception_groups')->insertGetId([
                // Same fingerprint formula as ExceptionGrouper, so live occurrences join these groups.
                'fingerprint' => ExceptionGrouper::fingerprint($definition['class'], $definition['file'], $definition['function']),
                'class' => $definition['class'],
                'message' => $definition['message'],
                'file' => $definition['file'],
                'line' => $definition['line'],
                'first_seen_at' => $this->time($this->start),
                'last_seen_at' => $this->time($this->start),
                'first_release' => 'v3.13.4',
                'occurrences' => 0,
            ]);

            $this->groups[$key] = [
                'id' => $id,
                'count' => 0,
                'first' => null,
                'last' => null,
                'release' => null,
                'message' => $definition['message'],
                'trace' => json_encode(array_map(fn (array $f) => ['file' => $f[0], 'line' => $f[1], 'call' => $f[2], 'app' => $f[3]], $definition['frames'])),
            ];
        }
    }

    private function finishExceptionGroups(): void
    {
        foreach ($this->groups as $group) {
            if ($group['count'] === 0) {
                DB::table('obs_exception_groups')->where('id', $group['id'])->delete();

                continue;
            }

            DB::table('obs_exception_groups')->where('id', $group['id'])->update([
                'occurrences' => $group['count'],
                'first_seen_at' => $this->time($group['first']),
                'last_seen_at' => $this->time($group['last']),
                'first_release' => $group['release'],
            ]);
        }
    }

    /**
     * Requests per minute: a diurnal curve peaking mid-afternoon UTC, half traffic at weekends.
     */
    private function rpm(int $minute): float
    {
        $hour = ($minute % 86400) / 3600;
        $diurnal = 0.18 + 0.82 * exp(-(($hour - 14) ** 2) / (2 * 3.5 ** 2));
        $weekend = in_array((int) gmdate('N', $minute), [6, 7], true) ? 0.5 : 1.0;

        return self::PEAK_RPM * $diurnal * $weekend * max(0.5, 1 + 0.08 * $this->gauss());
    }

    /**
     * @return array<string, int> jobs enqueued this minute, per job class
     */
    private function simulateRequests(int $minute, RollupBuffer $buffer): array
    {
        $rpm = $this->rpm($minute);
        $arrivals = ['App\Jobs\SendInvoiceEmail' => 0, 'App\Jobs\GenerateInvoicePdf' => 0, 'App\Jobs\SyncPaymentToLedger' => 0];

        $shares = [
            'GET /ledgerline/invoices' => 0.22,
            'GET /ledgerline/invoices/{invoice}' => 0.25,
            'GET /ledgerline/invoices/{invoice}/pdf' => 0.08,
            'POST /ledgerline/invoices/{invoice}/send' => 0.05,
            'GET /ledgerline/customers/{customer}' => 0.12,
            'GET /ledgerline/search' => 0.12,
            'GET /ledgerline/reports/aging' => 0.02,
            'POST /ledgerline/webhooks/payments' => 0.10,
            'GET [unmatched]' => 0.04,
        ];

        foreach ($shares as $route => $share) {
            $count = $this->poisson($rpm * $share);

            for ($i = 0; $i < $count; $i++) {
                $spawned = $this->simulateRequest($route, $minute + mt_rand(0, 59999) / 1000, $buffer);

                foreach ($spawned as $job) {
                    $arrivals[$job]++;
                }
            }
        }

        return $arrivals;
    }

    /**
     * @return list<string> job classes the request dispatched
     */
    private function simulateRequest(string $route, float $at, RollupBuffer $buffer): array
    {
        $timestamp = (int) $at;
        $status = 200;
        $exception = null;
        $spawned = [];
        $nPlusOne = false;

        /** @var list<array{0: string, 1: int, 2: float}> $queries template, count, median ms */
        [$median, $sigma, $queries] = match ($route) {
            'GET /ledgerline/invoices' => $this->after('v3.15.0', $timestamp) && ! $this->after('v3.15.1', $timestamp)
                ? [360, 0.35, [['inv_count', 1, 1.5], ['inv_page', 1, 4], ['cust_eq', 25, 0.5], ['lines_eq', 25, 0.9]]]
                : [95, 0.45, [['inv_count', 1, 1.5], ['inv_page', 1, 4], ['cust_in', 1, 1.2], ['lines_in', 1, 2.5]]],
            'GET /ledgerline/invoices/{invoice}' => [45, 0.4, [['inv_by_number', 1, 0.6], ['cust_in', 1, 0.5], ['lines_in', 1, 1.1], ['pay_in', 1, 0.8]]],
            'GET /ledgerline/invoices/{invoice}/pdf' => [420, 0.5, [['inv_by_number', 1, 0.6], ['cust_in', 1, 0.5], ['lines_in', 1, 1.1]]],
            'POST /ledgerline/invoices/{invoice}/send' => [38, 0.35, [['inv_by_number', 1, 0.6], ['jobs_insert', 2, 1.8]]],
            'GET /ledgerline/customers/{customer}' => [70, 0.45, [['cust_by_id', 1, 0.5], ['cust_invoices_sum', 1, 14]]],
            'GET /ledgerline/search' => [32, 0.5, [['search_cust', 1, 6], ['search_inv', 1, 9]]],
            'GET /ledgerline/reports/aging' => $this->after('v3.14.1', $timestamp)
                ? [650, 0.3, [['aging', 1, 560]]]
                : [2400, 0.35, [['aging', 1, 2150]]],
            'POST /ledgerline/webhooks/payments' => [55, 0.4, [['webhook_exists', 1, 0.7], ['inv_lock', 1, 1.2], ['pay_by_ref', 1, 0.6], ['pay_insert', 1, 1.4], ['pay_sum', 1, 0.9], ['jobs_insert', 1, 1.8]]],
            default => [8, 0.4, []],
        };

        if ($route === 'GET [unmatched]') {
            $status = 404;
        }

        if ($route === 'GET /ledgerline/invoices/{invoice}/pdf' && $this->after('v3.15.0', $timestamp) && $this->chance(0.025)) {
            [$status, $exception, $median, $queries] = [500, 'vat', 60, array_slice($queries, 0, 3)];
        }

        if ($route === 'POST /ledgerline/webhooks/payments') {
            if ($this->chance(0.03)) {
                [$status, $median, $queries] = [422, 20, [['webhook_exists', 1, 0.7]]];
            } elseif ($this->chance(0.003)) {
                [$status, $exception, $median, $queries] = [500, 'deadlock', 1100, array_slice($queries, 0, 2)];
            } else {
                $spawned[] = 'App\Jobs\SyncPaymentToLedger';
            }
        }

        if ($route === 'POST /ledgerline/invoices/{invoice}/send') {
            array_push($spawned, 'App\Jobs\SendInvoiceEmail', 'App\Jobs\GenerateInvoicePdf');
        }

        // Expand query templates into individual executions laid out on a timeline.
        $executions = [];
        $offset = 1.5 + $this->uniform() * 2;
        $queryMs = 0.0;
        $interleaved = $this->interleave($queries);

        foreach ($interleaved as [$key, $queryMedian]) {
            $duration = $key === 'aging' ? $this->lognormal($queryMedian, 0.3) : $this->lognormal($queryMedian, 0.55);
            $executions[] = [$key, $duration, $offset];
            $offset += $duration + 0.1 + $this->uniform() * 1.2;
            $queryMs += $duration;
        }

        $duration = max($this->lognormal($median, $sigma), $offset + 1 + $this->uniform() * 3);

        $repeats = array_count_values(array_column($executions, 0));
        $nPlusOne = ($repeats['cust_eq'] ?? 0) >= 5;

        $buffer->addRequest($timestamp, $route, $status, $duration, count($executions), $queryMs, $nPlusOne);

        foreach ($executions as [$key, $queryDuration]) {
            $buffer->addQuery($timestamp, $this->templates[$key]['fingerprint'], $queryDuration);
        }

        if ($nPlusOne) {
            $buffer->addNPlusOne($timestamp, $this->templates['cust_eq']['fingerprint']);
            $buffer->addNPlusOne($timestamp, $this->templates['lines_eq']['fingerprint']);
        }

        $this->stats['requests']++;
        $this->stats['queries'] += count($executions);

        if ($exception !== null) {
            $buffer->addException($timestamp, $this->groups[$exception]['id']);
        }

        $slowestQuery = $executions === [] ? 0 : max(array_column($executions, 1));
        $reason = match (true) {
            $status >= 500 => 'error',
            $duration >= 1000 => 'slow',
            $slowestQuery >= 100 => 'slow_query',
            $nPlusOne => $this->chance(self::N_PLUS_ONE_KEEP) ? 'n_plus_one' : null,
            default => $this->chance(self::SAMPLE_RATE) ? 'random' : null,
        };

        if ($reason !== null) {
            $requestId = $this->nextRequestId++;
            $this->stats['kept_requests']++;
            [$method, $path] = explode(' ', $route, 2);

            $this->raw['obs_requests'][] = [
                'id' => $requestId,
                'trace_id' => (string) Str::ulid(),
                'occurred_at' => $this->time($at),
                'method' => $method,
                'route' => $route,
                'uri' => $this->exampleUri($path),
                'status' => $status,
                'duration_ms' => round($duration, 3),
                'memory_mb' => round(4 + $this->uniform() * 4 + ($route === 'GET /ledgerline/reports/aging' ? 12 : 0), 2),
                'query_count' => count($executions),
                'query_ms' => round($queryMs, 3),
                'n_plus_one' => $nPlusOne,
                'sample_reason' => $reason,
                'release' => $this->releaseAt($timestamp),
                'user_id' => null,
            ];

            foreach ($executions as [$key, $queryDuration, $queryOffset]) {
                $this->raw['obs_queries'][] = $this->queryRow($key, $queryDuration, $queryOffset, $at, $requestId, null);
            }

            if ($exception !== null) {
                $this->recordOccurrence($exception, $at, $requestId, null);
            }
        } elseif ($exception !== null) {
            $this->recordOccurrence($exception, $at, null, null);
        }

        return $spawned;
    }

    /**
     * Turn [template, count, median] into an execution order. Lazy loads alternate the way
     * a loop over invoices touching ->customer and ->lines would.
     *
     * @param  list<array{0: string, 1: int, 2: float}>  $queries
     * @return list<array{0: string, 1: float}>
     */
    private function interleave(array $queries): array
    {
        $ordered = [];
        $loop = [];

        foreach ($queries as [$key, $count, $median]) {
            if ($count >= 5) {
                $loop[] = [$key, $count, $median];

                continue;
            }

            for ($i = 0; $i < $count; $i++) {
                $ordered[] = [$key, $median];
            }
        }

        if ($loop !== []) {
            for ($i = 0; $i < $loop[0][1]; $i++) {
                foreach ($loop as [$key, , $median]) {
                    $ordered[] = [$key, $median];
                }
            }
        }

        return $ordered;
    }

    /**
     * A fluid model of each queue: FIFO cohorts, a worker pool with finite capacity, and
     * retries re-entering the queue after their backoff.
     *
     * @param  array<string, int>  $arrivals
     */
    private function simulateQueues(int $minute, array $arrivals, RollupBuffer $buffer): void
    {
        $queueOf = ['App\Jobs\SendInvoiceEmail' => 'mail', 'App\Jobs\GenerateInvoicePdf' => 'pdf', 'App\Jobs\SyncPaymentToLedger' => 'webhooks'];

        foreach ($arrivals as $job => $count) {
            if ($count > 0) {
                $this->queues[$queueOf[$job]][] = ['at' => $minute, 'attempt' => 1, 'count' => $count, 'job' => $job];
            }
        }

        // Hourly payment reminders in business hours, and the statement run that met the SMTP incident.
        $hour = (int) gmdate('G', $minute);

        if ($minute % 3600 === 0 && $hour >= 8 && $hour <= 17 && (int) gmdate('N', $minute) <= 5) {
            $this->queues['mail'][] = ['at' => $minute, 'attempt' => 1, 'count' => 30, 'job' => 'App\Jobs\SendInvoiceEmail'];
        }

        if ($minute === $this->incidentStart) {
            $this->queues['mail'][] = ['at' => $minute, 'attempt' => 1, 'count' => 900, 'job' => 'App\Jobs\SendInvoiceEmail'];
        }

        $workers = ['mail' => 2, 'pdf' => 2, 'webhooks' => 1];

        foreach ($this->queues as $queue => &$cohorts) {
            usort($cohorts, fn (array $a, array $b) => $a['at'] <=> $b['at']);
            $budgetMs = $workers[$queue] * 60000.0;
            $retries = [];

            foreach ($cohorts as &$cohort) {
                if ($cohort['at'] > $minute || $budgetMs <= 0) {
                    continue;
                }

                while ($cohort['count'] > 0 && $budgetMs > 0) {
                    $start = $minute + (1 - $budgetMs / ($workers[$queue] * 60000.0)) * 60;
                    [$status, $runtime, $exception] = $this->runJob($cohort['job'], $cohort['attempt'], (int) $start);
                    $budgetMs -= $runtime;
                    $cohort['count']--;

                    $wait = $cohort['attempt'] === 1 ? max(5.0, ($start - $cohort['at']) * 1000 + mt_rand(5, 900)) : null;
                    $this->recordJob($buffer, $queue, $cohort['job'], $status, $cohort['attempt'], $start, $runtime, $wait, $exception);

                    if ($status === 'released') {
                        $retries[] = ['at' => $minute + ($cohort['attempt'] === 1 ? 60 : 300), 'attempt' => $cohort['attempt'] + 1, 'count' => 1, 'job' => $cohort['job']];
                    }
                }
            }

            unset($cohort);
            $cohorts = array_values(array_filter([...$cohorts, ...$retries], fn (array $c) => $c['count'] > 0));

            $ready = array_filter($cohorts, fn (array $c) => $c['at'] <= $minute + 60);
            $pending = array_sum(array_column($ready, 'count'));
            $oldest = $ready === [] ? null : $minute + 60 - min(array_column($ready, 'at'));

            $this->raw['obs_queue_snapshots'][] = [
                'taken_at' => $minute + 60,
                'queue' => $queue,
                'pending' => $pending,
                'delayed' => array_sum(array_column(array_filter($cohorts, fn (array $c) => $c['at'] > $minute + 60), 'count')),
                'reserved' => min($pending, $workers[$queue]),
                'oldest_pending_s' => $pending > 0 ? $oldest : null,
            ];
        }

        unset($cohorts);
    }

    /**
     * @return array{0: 'processed'|'failed'|'released', 1: float, 2: ?string}
     */
    private function runJob(string $job, int $attempt, int $at): array
    {
        $incident = $at >= $this->incidentStart && $at < $this->incidentEnd;

        [$median, $failure, $tries, $exception] = match ($job) {
            'App\Jobs\SendInvoiceEmail' => $incident ? [7000, 0.35, 3, 'smtp'] : [240, 0.03, 3, 'smtp'],
            'App\Jobs\GenerateInvoicePdf' => [480, $this->after('v3.15.0', $at) ? 0.025 : 0.0, 2, 'vat'],
            default => [70, 0.005, 5, null],
        };

        if ($this->chance($failure)) {
            $runtime = $exception === 'smtp' && $incident ? $this->lognormal(9000, 0.2) : $this->lognormal($median / 3, 0.4);

            // During the incident some attempts hit the job timeout, which fails the job outright.
            $timedOut = $incident && $exception === 'smtp' && $this->chance(0.3);

            return [$attempt >= $tries || $timedOut ? 'failed' : 'released', $runtime, $exception];
        }

        return ['processed', $this->lognormal($median, 0.4), null];
    }

    private function recordJob(RollupBuffer $buffer, string $queue, string $job, string $status, int $attempt, float $at, float $runtime, ?float $wait, ?string $exception): void
    {
        $timestamp = (int) $at;
        $buffer->addJob($timestamp, $queue, $job, $status, $runtime, $wait);
        $this->stats['jobs']++;

        $queries = match ($job) {
            'App\Jobs\SendInvoiceEmail' => [['inv_by_id', 0.5], ['inv_update_sent', 1.1], ['jobs_delete', 0.7]],
            'App\Jobs\GenerateInvoicePdf' => [['inv_by_id', 0.5], ['cust_in', 0.5], ['lines_in', 1.1], ['jobs_delete', 0.7]],
            default => [['pay_by_id', 0.5], ['pay_update_synced', 1.0], ['jobs_delete', 0.7]],
        };

        if ($status !== 'processed') {
            array_pop($queries);
        }

        $executions = [];
        $offset = 0.8;

        foreach ($queries as [$key, $median]) {
            $duration = $this->lognormal($median, 0.5);
            $executions[] = [$key, $duration, $offset];
            $offset += $duration + 0.3;
            $buffer->addQuery($timestamp, $this->templates[$key]['fingerprint'], $duration);
        }

        if ($exception !== null) {
            $buffer->addException($timestamp, $this->groups[$exception]['id']);
        }

        $keep = $status !== 'processed' || $runtime >= 5000 || $this->chance(0.02);

        if (! $keep) {
            return;
        }

        $runId = $this->nextJobRunId++;
        $this->stats['kept_jobs']++;

        $this->raw['obs_job_runs'][] = [
            'id' => $runId,
            'job_uuid' => (string) Str::uuid(),
            'occurred_at' => $this->time($at),
            'connection' => 'database',
            'queue' => $queue,
            'job' => $job,
            'status' => $status,
            'attempt' => $attempt,
            'wait_ms' => $wait === null ? null : round($wait, 3),
            'runtime_ms' => round($runtime, 3),
            'query_count' => count($executions),
            'sample_reason' => match (true) {
                $status !== 'processed' => 'error',
                $runtime >= 5000 => 'slow',
                default => 'random',
            },
            'release' => $this->releaseAt($timestamp),
        ];

        foreach ($executions as [$key, $duration, $offset]) {
            $this->raw['obs_queries'][] = $this->queryRow($key, $duration, $offset, $at, null, $runId);
        }

        if ($exception !== null) {
            $this->recordOccurrence($exception, $at, null, $runId);
        }
    }

    private function recordOccurrence(string $key, float $at, ?int $requestId, ?int $jobRunId): void
    {
        $group = &$this->groups[$key];
        $group['count']++;
        $group['first'] ??= $at;
        $group['last'] = $at;
        $group['release'] ??= $this->releaseAt((int) $at);

        $this->raw['obs_exceptions'][] = [
            'group_id' => $group['id'],
            'request_id' => $requestId,
            'job_run_id' => $jobRunId,
            'occurred_at' => $this->time($at),
            'message' => $group['message'],
            'trace' => $group['trace'],
            'release' => $this->releaseAt((int) $at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queryRow(string $key, float $duration, float $offset, float $at, ?int $requestId, ?int $jobRunId): array
    {
        $template = $this->templates[$key];

        return [
            'request_id' => $requestId,
            'job_run_id' => $jobRunId,
            'occurred_at' => $this->time($at + $offset / 1000),
            'fingerprint' => $template['fingerprint'],
            'sql' => $template['sql'],
            'duration_ms' => round($duration, 3),
            'offset_ms' => round($offset, 3),
            'location' => $template['location'],
        ];
    }

    private function exampleUri(string $path): string
    {
        return match ($path) {
            '[unmatched]' => mt_rand(0, 1) ? '/ledgerline/wp-login.php' : '/ledgerline/invoices/export.csv',
            default => strtr($path, [
                '{invoice}' => sprintf('INV-%06d', 100000 + mt_rand(1, 3000)),
                '{customer}' => (string) mt_rand(1, 300),
            ]),
        };
    }

    private function flushRaw(bool $force = false): void
    {
        // Parents before children, so foreign keys hold.
        foreach (['obs_requests', 'obs_job_runs', 'obs_queries', 'obs_exceptions', 'obs_queue_snapshots'] as $table) {
            $rows = $this->raw[$table] ?? [];

            if ($rows === [] || (! $force && count($rows) < 50 && $table !== 'obs_requests' && $table !== 'obs_job_runs')) {
                continue;
            }

            foreach (array_chunk($rows, 250) as $chunk) {
                DB::table($table)->insert($chunk);
            }

            $this->raw[$table] = [];
        }
    }

    private function time(float $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', (int) $timestamp).sprintf('.%03d', (int) (fmod($timestamp, 1) * 1000));
    }

    private function uniform(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    private function chance(float $probability): bool
    {
        return $probability > 0 && $this->uniform() < $probability;
    }

    private function gauss(): float
    {
        return sqrt(-2 * log(max(1e-12, $this->uniform()))) * cos(2 * M_PI * $this->uniform());
    }

    private function lognormal(float $median, float $sigma): float
    {
        return $median * exp($sigma * $this->gauss());
    }

    private function poisson(float $lambda): int
    {
        $limit = exp(-$lambda);
        $k = 0;
        $p = 1.0;

        do {
            $k++;
            $p *= $this->uniform();
        } while ($p > $limit);

        return $k - 1;
    }
}
