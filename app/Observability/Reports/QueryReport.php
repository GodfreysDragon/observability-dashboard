<?php

namespace App\Observability\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Query shapes (fingerprints) ranked by the database time they cost.
 */
final class QueryReport
{
    public const array SORTS = ['total_ms', 'calls', 'p95', 'max_ms', 'n_plus_one'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function fingerprints(TimeRange $range, string $sort = 'total_ms', int $limit = 100): array
    {
        $rows = Rollups::aggregate('obs_query_rollups', $range, ['calls', 'total_ms', 'n_plus_one'], ['max_ms'], groupBy: ['fingerprint']);
        $sql = DB::table('obs_query_fingerprints')->whereIn('fingerprint', $rows->pluck('fingerprint'))->pluck('sql', 'fingerprint');
        $totalTime = max(1e-9, (float) $rows->sum('total_ms'));

        return $rows
            ->filter(fn (object $row) => $row->calls > 0)
            ->map(fn (object $row) => [
                'fingerprint' => $row->fingerprint,
                'sql' => $sql[$row->fingerprint] ?? null,
                'calls' => (int) $row->calls,
                'calls_per_minute' => round($row->calls / $range->totalSeconds() * 60, 2),
                'total_ms' => round((float) $row->total_ms, 1),
                'time_share' => round($row->total_ms / $totalTime, 4),
                'avg_ms' => round($row->total_ms / $row->calls, 2),
                ...Rollups::percentiles($row, 'max_ms'),
                'max_ms' => round((float) $row->max_ms, 2),
                'n_plus_one' => (int) $row->n_plus_one,
            ])
            ->sortByDesc(fn (array $row) => $row[$sort] ?? 0)
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function detail(TimeRange $range, string $fingerprint): ?array
    {
        $meta = DB::table('obs_query_fingerprints')->where('fingerprint', $fingerprint)->first();

        if ($meta === null) {
            return null;
        }

        $scope = fn (Builder $query) => $query->where('fingerprint', $fingerprint);
        $total = Rollups::aggregate('obs_query_rollups', $range, ['calls', 'total_ms', 'n_plus_one'], ['max_ms'], $scope)->first();
        $series = Rollups::aggregate('obs_query_rollups', $range, ['calls', 'total_ms'], ['max_ms'], $scope, byTime: true);

        $points = [];

        foreach (Rollups::fill($range, $series) as $bucket => $row) {
            $points[] = [
                't' => $bucket,
                'calls_per_minute' => $row ? round($row->calls / $range->coveredSeconds($bucket) * 60, 2) : 0,
                ...($row && $row->calls ? Rollups::percentiles($row, 'max_ms') : ['p50' => null, 'p95' => null, 'p99' => null]),
            ];
        }

        $executions = DB::table('obs_queries')
            ->leftJoin('obs_requests', 'obs_requests.id', '=', 'obs_queries.request_id')
            ->leftJoin('obs_job_runs', 'obs_job_runs.id', '=', 'obs_queries.job_run_id')
            ->where('obs_queries.fingerprint', $fingerprint)
            ->whereBetween('obs_queries.occurred_at', [$range->fromDate(), $range->toDate()]);

        $calls = (int) ($total->calls ?? 0);

        return [
            'fingerprint' => $fingerprint,
            'sql' => $meta->sql,
            'first_seen_at' => $meta->first_seen_at,
            'calls' => $calls,
            'total_ms' => round((float) ($total->total_ms ?? 0), 1),
            'avg_ms' => $calls ? round($total->total_ms / $calls, 2) : null,
            ...($calls ? Rollups::percentiles($total, 'max_ms') : ['p50' => null, 'p95' => null, 'p99' => null]),
            'max_ms' => round((float) ($total->max_ms ?? 0), 2),
            'n_plus_one' => (int) ($total->n_plus_one ?? 0),
            'series' => $points,
            'callers' => (clone $executions)
                ->selectRaw('COALESCE(obs_requests.route, obs_job_runs.job) AS caller, obs_queries.location, COUNT(*) AS calls, COUNT(DISTINCT COALESCE(obs_queries.request_id, obs_queries.job_run_id)) AS traces')
                ->groupByRaw('COALESCE(obs_requests.route, obs_job_runs.job), obs_queries.location')
                ->orderByDesc('calls')
                ->limit(10)
                ->get(),
            'slowest' => (clone $executions)
                ->orderByDesc('obs_queries.duration_ms')
                ->limit(10)
                ->get(['obs_queries.duration_ms', 'obs_queries.occurred_at', 'obs_queries.location', 'obs_queries.request_id', 'obs_queries.job_run_id', 'obs_requests.route', 'obs_job_runs.job']),
        ];
    }
}
