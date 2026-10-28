<?php

namespace App\Observability\Reports;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Kept traces (requests and job runs) and their query waterfalls.
 */
final class TraceReport
{
    public const array REASONS = ['error', 'exception', 'slow', 'slow_query', 'n_plus_one', 'random'];

    /**
     * @param  array{route?: ?string, status?: ?string, reason?: ?string, min_ms?: ?int}  $filters
     */
    public static function requests(TimeRange $range, array $filters, int $perPage = 50): CursorPaginator
    {
        return DB::table('obs_requests')
            ->whereBetween('occurred_at', [$range->fromDate(), $range->toDate()])
            ->when($filters['route'] ?? null, fn (Builder $q, string $route) => $q->where('route', $route))
            ->when($filters['status'] ?? null, fn (Builder $q, string $class) => $q->whereBetween('status', [(int) $class[0] * 100, (int) $class[0] * 100 + 99]))
            ->when($filters['reason'] ?? null, fn (Builder $q, string $reason) => $q->where('sample_reason', $reason))
            ->when($filters['min_ms'] ?? null, fn (Builder $q, int $ms) => $q->where('duration_ms', '>=', $ms))
            ->orderByDesc('id')
            ->select(['id', 'trace_id', 'occurred_at', 'method', 'route', 'uri', 'status', 'duration_ms', 'query_count', 'query_ms', 'n_plus_one', 'sample_reason', 'release'])
            ->cursorPaginate($perPage);
    }

    /**
     * @param  'request'|'job'  $kind
     * @return array<string, mixed>|null
     */
    public static function detail(string $kind, int $id): ?array
    {
        $table = $kind === 'request' ? 'obs_requests' : 'obs_job_runs';
        $owner = $kind === 'request' ? 'request_id' : 'job_run_id';
        $trace = DB::table($table)->find($id);

        if ($trace === null) {
            return null;
        }

        $queries = DB::table('obs_queries')
            ->where($owner, $id)
            ->orderBy('offset_ms')
            ->get(['id', 'fingerprint', 'sql', 'duration_ms', 'offset_ms', 'location']);

        $threshold = config('observability.n_plus_one_threshold');
        $repeats = $queries->filter(fn (object $q) => preg_match('/^\s*select\b/i', $q->sql))->countBy('fingerprint');

        $exceptions = DB::table('obs_exceptions')
            ->join('obs_exception_groups', 'obs_exception_groups.id', '=', 'obs_exceptions.group_id')
            ->where("obs_exceptions.{$owner}", $id)
            ->get(['obs_exceptions.id', 'obs_exceptions.group_id', 'obs_exception_groups.class', 'obs_exceptions.message', 'obs_exceptions.trace'])
            ->map(fn (object $e) => ['trace' => json_decode($e->trace, true)] + (array) $e);

        return [
            'kind' => $kind,
            'trace' => $trace,
            'queries' => $queries->map(fn (object $q) => (array) $q + [
                'repeats' => $repeats[$q->fingerprint] ?? 1,
                'n_plus_one' => ($repeats[$q->fingerprint] ?? 0) >= $threshold,
            ]),
            'n_plus_one' => $repeats->filter(fn (int $count) => $count >= $threshold)
                ->map(fn (int $count, string $fingerprint) => [
                    'fingerprint' => $fingerprint,
                    'count' => $count,
                    'sql' => $queries->firstWhere('fingerprint', $fingerprint)->sql,
                    'location' => $queries->firstWhere('fingerprint', $fingerprint)->location,
                ])
                ->values(),
            'exceptions' => $exceptions,
            'stored_queries' => $queries->count(),
        ];
    }
}
