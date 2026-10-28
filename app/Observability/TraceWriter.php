<?php

namespace App\Observability;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Persists a finished trace: rollups always (100% of traffic), the full trace only
 * when the tail-sampling policy keeps it.
 */
final class TraceWriter
{
    public function __construct(
        private readonly RollupWriter $rollups,
        private readonly ExceptionGrouper $grouper,
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    /**
     * @param  array{method: string, route: string, uri: string, status: int, duration_ms: float, memory_mb: float, user_id: mixed}  $request
     */
    public function writeRequest(Trace $trace, array $request): void
    {
        $timestamp = (int) floor($trace->startedAt);
        $nPlusOne = $trace->nPlusOneFingerprints($this->config->get('observability.n_plus_one_threshold'));

        $reason = $this->sampleReason(
            failed: $request['status'] >= 500,
            trace: $trace,
            slow: $request['duration_ms'] >= $this->config->get('observability.slow_request_ms'),
            nPlusOne: $nPlusOne !== [],
        );

        $buffer = new RollupBuffer($this->config->get('observability.apdex_t_ms'));
        $buffer->addRequest($timestamp, $request['route'], $request['status'], $request['duration_ms'], $trace->queryCount, $trace->queryMs, $nPlusOne !== []);
        $this->bufferQueries($buffer, $trace, $timestamp, $nPlusOne);

        DB::transaction(function () use ($trace, $request, $reason, $buffer, $timestamp, $nPlusOne) {
            $requestId = null;

            if ($reason !== null) {
                $requestId = DB::table('obs_requests')->insertGetId([
                    'trace_id' => (string) Str::ulid(),
                    'occurred_at' => $this->time($trace->startedAt),
                    'method' => $request['method'],
                    'route' => $request['route'],
                    'uri' => $request['uri'],
                    'status' => $request['status'],
                    'duration_ms' => round($request['duration_ms'], 3),
                    'memory_mb' => round($request['memory_mb'], 2),
                    'query_count' => $trace->queryCount,
                    'query_ms' => round($trace->queryMs, 3),
                    'n_plus_one' => $nPlusOne !== [],
                    'sample_reason' => $reason,
                    'release' => $this->config->get('observability.release'),
                    'user_id' => $request['user_id'],
                ]);

                $this->insertQueries($trace, ['request_id' => $requestId]);
            }

            $this->writeExceptions($trace, $buffer, $timestamp, ['request_id' => $requestId]);
            $this->rememberFingerprints($trace);
            $this->rollups->write($buffer);
        });
    }

    /**
     * @param  'processed'|'failed'|'released'  $status
     */
    public function writeJob(Trace $trace, string $status, float $runtimeMs): void
    {
        $timestamp = (int) floor($trace->startedAt);
        $meta = $trace->meta;
        $nPlusOne = $trace->nPlusOneFingerprints($this->config->get('observability.n_plus_one_threshold'));

        // Wait is only meaningful for the first attempt; retries also wait out their backoff.
        $waitMs = $meta['attempt'] === 1 && $meta['queued_at'] !== null
            ? max(0.0, ($trace->startedAt - $meta['queued_at']) * 1000)
            : null;

        $reason = $this->sampleReason(
            failed: $status !== 'processed',
            trace: $trace,
            slow: $runtimeMs >= $this->config->get('observability.slow_job_ms'),
            nPlusOne: $nPlusOne !== [],
        );

        $buffer = new RollupBuffer($this->config->get('observability.apdex_t_ms'));
        $buffer->addJob($timestamp, $meta['queue'], $meta['job'], $status, $runtimeMs, $waitMs);
        $this->bufferQueries($buffer, $trace, $timestamp, $nPlusOne);

        DB::transaction(function () use ($trace, $meta, $status, $runtimeMs, $waitMs, $reason, $buffer, $timestamp) {
            $jobRunId = null;

            if ($reason !== null) {
                $jobRunId = DB::table('obs_job_runs')->insertGetId([
                    'job_uuid' => $meta['uuid'],
                    'occurred_at' => $this->time($trace->startedAt),
                    'connection' => $meta['connection'],
                    'queue' => $meta['queue'],
                    'job' => $meta['job'],
                    'status' => $status,
                    'attempt' => $meta['attempt'],
                    'wait_ms' => $waitMs === null ? null : round($waitMs, 3),
                    'runtime_ms' => round($runtimeMs, 3),
                    'query_count' => $trace->queryCount,
                    'sample_reason' => $reason,
                    'release' => $this->config->get('observability.release'),
                ]);

                $this->insertQueries($trace, ['job_run_id' => $jobRunId]);
            }

            $this->writeExceptions($trace, $buffer, $timestamp, ['job_run_id' => $jobRunId]);
            $this->rememberFingerprints($trace);
            $this->rollups->write($buffer);
        });
    }

    public function writeStandaloneException(Throwable $exception): void
    {
        $trace = new Trace('request');
        $trace->exceptions[] = $exception;
        $buffer = new RollupBuffer;

        DB::transaction(function () use ($trace, $buffer) {
            $this->writeExceptions($trace, $buffer, (int) floor($trace->startedAt), []);
            $this->rollups->write($buffer);
        });
    }

    /**
     * Tail-based sampling: decide after the fact, keeping every interesting trace.
     */
    private function sampleReason(bool $failed, Trace $trace, bool $slow, bool $nPlusOne): ?string
    {
        return match (true) {
            $failed => 'error',
            $trace->exceptions !== [] => 'exception',
            $slow => 'slow',
            $trace->slowestQueryMs >= $this->config->get('observability.slow_query_ms') => 'slow_query',
            $nPlusOne => 'n_plus_one',
            mt_rand() / mt_getrandmax() < $this->config->get('observability.sample_rate') => 'random',
            default => null,
        };
    }

    /**
     * @param  array<string, int>  $nPlusOne
     */
    private function bufferQueries(RollupBuffer $buffer, Trace $trace, int $timestamp, array $nPlusOne): void
    {
        foreach ($trace->shapes as $fingerprint => $shape) {
            foreach ($shape['durations'] as $durationMs) {
                $buffer->addQuery($timestamp, $fingerprint, $durationMs);
            }
        }

        foreach (array_keys($nPlusOne) as $fingerprint) {
            $buffer->addNPlusOne($timestamp, $fingerprint);
        }
    }

    /**
     * @param  array<string, int|null>  $owner
     */
    private function insertQueries(Trace $trace, array $owner): void
    {
        $rows = array_map(fn (array $query) => $owner + [
            'occurred_at' => $this->time($trace->startedAt + $query['offset_ms'] / 1000),
            'fingerprint' => $query['fingerprint'],
            'sql' => $query['sql'],
            'duration_ms' => round($query['duration_ms'], 3),
            'offset_ms' => round($query['offset_ms'], 3),
            'location' => $query['location'] === null ? null : mb_substr($query['location'], 0, 255),
        ], $trace->queries);

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('obs_queries')->insert($chunk);
        }
    }

    private function rememberFingerprints(Trace $trace): void
    {
        $rows = [];

        foreach ($trace->shapes as $fingerprint => $shape) {
            $rows[] = [
                'fingerprint' => $fingerprint,
                'sql' => $shape['sql'],
                'connection' => DB::getDefaultConnection(),
                'first_seen_at' => $this->time($trace->startedAt),
            ];
        }

        if ($rows !== []) {
            DB::table('obs_query_fingerprints')->insertOrIgnore($rows);
        }
    }

    /**
     * @param  array<string, int|null>  $owner
     */
    private function writeExceptions(Trace $trace, RollupBuffer $buffer, int $timestamp, array $owner): void
    {
        foreach ($trace->exceptions as $exception) {
            $described = $this->grouper->describe($exception);
            $groupId = $this->upsertGroup($described, $trace->startedAt);
            $buffer->addException($timestamp, $groupId);

            if (! $this->withinOccurrenceBudget($groupId, $timestamp)) {
                continue;
            }

            DB::table('obs_exceptions')->insert($owner + [
                'group_id' => $groupId,
                'occurred_at' => $this->time($trace->startedAt),
                'message' => $described['message'],
                'trace' => json_encode($described['trace']),
                'release' => $this->config->get('observability.release'),
            ]);
        }
    }

    /**
     * @param  array{fingerprint: string, class: string, message: string, file: string, line: int}  $described
     */
    private function upsertGroup(array $described, float $at): int
    {
        $time = $this->time($at);

        DB::table('obs_exception_groups')->insertOrIgnore([
            'fingerprint' => $described['fingerprint'],
            'class' => $described['class'],
            'message' => $described['message'],
            'file' => $described['file'],
            'line' => $described['line'],
            'first_seen_at' => $time,
            'last_seen_at' => $time,
            'first_release' => $this->config->get('observability.release'),
            'occurrences' => 0,
        ]);

        // A recurrence reopens a resolved group: that is a regression, not old news. The
        // fingerprint ignores line numbers, so keep the location pointing at current code.
        DB::table('obs_exception_groups')->where('fingerprint', $described['fingerprint'])->update([
            'file' => $described['file'],
            'line' => $described['line'],
            'last_seen_at' => $time,
            'occurrences' => DB::raw('occurrences + 1'),
            'resolved_at' => null,
        ]);

        return (int) DB::table('obs_exception_groups')->where('fingerprint', $described['fingerprint'])->value('id');
    }

    private function withinOccurrenceBudget(int $groupId, int $timestamp): bool
    {
        $key = 'obs:exceptions:'.$groupId.':'.intdiv($timestamp, 60);
        $this->cache->add($key, 0, 120);

        return $this->cache->increment($key) <= $this->config->get('observability.exception_occurrences_per_minute');
    }

    private function time(float $timestamp): string
    {
        return Carbon::createFromTimestampMs((int) round($timestamp * 1000), 'UTC')->format('Y-m-d H:i:s.v');
    }
}
