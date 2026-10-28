<?php

namespace App\Observability\Reports;

use App\Observability\Histogram;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * HTTP request metrics from obs_request_rollups: KPIs, time series, the per-route table
 * and the per-route drill-down.
 */
final class RequestReport
{
    public const array SUMS = ['count', 'server_errors', 'client_errors', 'satisfied', 'tolerating', 'duration_sum', 'query_count_sum', 'query_ms_sum', 'n_plus_one'];

    /**
     * @return array<string, float|int|null>
     */
    public static function metrics(?object $row, int $seconds): array
    {
        $count = (int) ($row->count ?? 0);

        return [
            'count' => $count,
            'rpm' => round($count / max(1, $seconds) * 60, 2),
            'error_rate' => Rollups::ratio($row->server_errors ?? 0, $count),
            'client_error_rate' => Rollups::ratio($row->client_errors ?? 0, $count),
            'apdex' => $count ? round((($row->satisfied ?? 0) + ($row->tolerating ?? 0) / 2) / $count, 3) : null,
            'avg' => Rollups::round($count ? $row->duration_sum / $count : null),
            ...($row ? Rollups::percentiles($row, 'duration_max') : ['p50' => null, 'p95' => null, 'p99' => null]),
            'max' => Rollups::round(isset($row->duration_max) ? (float) $row->duration_max : null),
            'queries_per_request' => Rollups::round($count ? $row->query_count_sum / $count : null),
            'query_ms_share' => Rollups::ratio($row->query_ms_sum ?? 0, $row->duration_sum ?? 0, 3),
            'n_plus_one' => (int) ($row->n_plus_one ?? 0),
        ];
    }

    /**
     * @return array<string, float|int|null>
     */
    public static function totals(TimeRange $range, ?string $route = null): array
    {
        $row = Rollups::aggregate('obs_request_rollups', $range, self::SUMS, ['duration_max'], self::routeScope($route))->first();

        return self::metrics($row?->count ? $row : null, $range->totalSeconds());
    }

    /**
     * @return list<array<string, float|int|null>>
     */
    public static function series(TimeRange $range, ?string $route = null): array
    {
        $rows = Rollups::aggregate('obs_request_rollups', $range, self::SUMS, ['duration_max'], self::routeScope($route), byTime: true);
        $points = [];

        foreach (Rollups::fill($range, $rows) as $bucket => $row) {
            $metrics = self::metrics($row, $range->coveredSeconds($bucket));
            $points[] = ['t' => $bucket] + array_intersect_key($metrics, array_flip(['count', 'rpm', 'error_rate', 'p50', 'p95', 'p99', 'apdex', 'queries_per_request']));
        }

        return $points;
    }

    /**
     * Every route in the window with its latency profile, error rates and the share of
     * total server time it consumed (count x mean): the "where does the time go" ranking.
     *
     * @param  bool  $comparePrevious  include each route's p95 change against the previous window
     * @return list<array<string, mixed>>
     */
    public static function routes(TimeRange $range, bool $comparePrevious = true): array
    {
        $current = Rollups::aggregate('obs_request_rollups', $range, self::SUMS, ['duration_max'], groupBy: ['route']);
        $previous = $comparePrevious
            ? Rollups::aggregate('obs_request_rollups', $range->previous(), ['count'], ['duration_max'], groupBy: ['route'])->keyBy('route')
            : collect();
        $totalTime = max(1e-9, (float) $current->sum('duration_sum'));

        return $current
            ->map(function (object $row) use ($range, $previous, $totalTime) {
                $metrics = self::metrics($row, $range->totalSeconds());
                $before = $previous->get($row->route);
                $previousP95 = $before && $before->count >= 20
                    ? Histogram::fromRow($before)->percentile(0.95, (float) $before->duration_max)
                    : null;

                return ['route' => $row->route] + $metrics + [
                    'time_share' => round($row->duration_sum / $totalTime, 4),
                    'p95_change' => Rollups::change($metrics['p95'], $previousP95),
                ];
            })
            ->sortByDesc('time_share')
            ->values()
            ->all();
    }

    /**
     * Latency distribution as histogram bars, trimmed to the populated buckets.
     *
     * @return list<array{from: float, to: ?float, count: int}>
     */
    public static function distribution(TimeRange $range, ?string $route = null): array
    {
        $row = Rollups::aggregate('obs_request_rollups', $range, ['count'], [], self::routeScope($route))->first();
        $counts = $row ? Histogram::fromRow($row)->counts() : [];
        $populated = array_keys(array_filter($counts));

        if ($populated === []) {
            return [];
        }

        $bars = [];

        foreach (range(min($populated), max($populated)) as $i) {
            $upper = Histogram::upperBound($i);
            $bars[] = ['from' => round(Histogram::lowerBound($i), 2), 'to' => is_infinite($upper) ? null : round($upper, 2), 'count' => $counts[$i]];
        }

        return $bars;
    }

    /**
     * The slowest kept traces for a route (sampled data: errors and slow requests are
     * always kept, so the tail is complete even when the body is sampled).
     *
     * @return list<object>
     */
    public static function slowestTraces(TimeRange $range, string $route, int $limit = 10): array
    {
        return DB::table('obs_requests')
            ->where('route', $route)
            ->whereBetween('occurred_at', [$range->fromDate(), $range->toDate()])
            ->orderByDesc('duration_ms')
            ->limit($limit)
            ->get(['id', 'occurred_at', 'uri', 'status', 'duration_ms', 'query_count', 'n_plus_one', 'sample_reason', 'release'])
            ->all();
    }

    /**
     * Which query shapes this route spends its database time on, from kept traces.
     *
     * @return list<array<string, mixed>>
     */
    public static function topQueries(TimeRange $range, string $route, int $limit = 8): array
    {
        $traces = DB::table('obs_requests')
            ->where('route', $route)
            ->whereBetween('occurred_at', [$range->fromDate(), $range->toDate()]);

        $traceCount = (clone $traces)->count();

        if ($traceCount === 0) {
            return [];
        }

        // `sql` is a reserved word in MySQL, so quote it the connection's way.
        $sql = DB::getQueryGrammar()->wrap('sql');

        return DB::table('obs_queries')
            ->joinSub((clone $traces)->select('id'), 'traces', 'traces.id', '=', 'obs_queries.request_id')
            ->groupBy('fingerprint')
            ->selectRaw("fingerprint, MIN({$sql}) AS {$sql}, COUNT(*) AS calls, SUM(duration_ms) AS total_ms, MAX(duration_ms) AS max_ms")
            ->orderByDesc('total_ms')
            ->limit($limit)
            ->get()
            ->map(fn (object $row) => [
                'fingerprint' => $row->fingerprint,
                'sql' => $row->sql,
                'calls_per_request' => round($row->calls / $traceCount, 1),
                'avg_ms' => round($row->total_ms / $row->calls, 2),
                'max_ms' => round((float) $row->max_ms, 2),
            ])
            ->all();
    }

    private static function routeScope(?string $route): ?\Closure
    {
        return $route === null ? null : fn (Builder $query) => $query->where('route', $route);
    }
}
