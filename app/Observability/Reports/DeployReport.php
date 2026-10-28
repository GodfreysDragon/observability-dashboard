<?php

namespace App\Observability\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Before/after comparison around each deploy. The windows are symmetric and never
 * cross a neighbouring deploy, so each release is judged only on its own traffic.
 */
final class DeployReport
{
    public const int MAX_WINDOW = 6 * 3600;

    public const int MIN_WINDOW = 15 * 60;

    /** A route needs this many requests on each side before its change is reported. */
    public const int MIN_ROUTE_SAMPLES = 30;

    /**
     * @return list<array<string, mixed>>
     */
    public static function list(int $limit = 12, ?int $now = null): array
    {
        $now ??= now()->getTimestamp();
        $deploys = DB::table('obs_deploys')->orderBy('deployed_at')->get();
        $results = [];

        foreach ($deploys as $i => $deploy) {
            $at = strtotime($deploy->deployed_at.' UTC');
            $previousAt = isset($deploys[$i - 1]) ? strtotime($deploys[$i - 1]->deployed_at.' UTC') : null;
            $nextAt = isset($deploys[$i + 1]) ? strtotime($deploys[$i + 1]->deployed_at.' UTC') : null;

            $window = min(self::MAX_WINDOW, $now - $at, $previousAt ? $at - $previousAt : PHP_INT_MAX, $nextAt ? $nextAt - $at : PHP_INT_MAX);

            $results[] = [
                'id' => $deploy->id,
                'release' => $deploy->release,
                'commit' => $deploy->commit,
                'deployed_by' => $deploy->deployed_by,
                'notes' => $deploy->notes,
                'deployed_at' => $at,
            ] + self::compare($deploy->release, $at, $window, $now);
        }

        return array_slice(array_reverse($results), 0, $limit);
    }

    /**
     * @return list<array{release: string, t: int}>
     */
    public static function markers(TimeRange $range): array
    {
        return DB::table('obs_deploys')
            ->whereBetween('deployed_at', [$range->fromDate(), $range->toDate()])
            ->orderBy('deployed_at')
            ->get(['release', 'deployed_at'])
            ->map(fn (object $deploy) => ['release' => $deploy->release, 't' => strtotime($deploy->deployed_at.' UTC')])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function compare(string $release, int $at, int $window, int $now): array
    {
        $newExceptions = DB::table('obs_exception_groups')
            ->where('first_release', $release)
            ->orderByDesc('occurrences')
            ->get(['id', 'class', 'message', 'occurrences']);

        if ($window < self::MIN_WINDOW) {
            return ['window_s' => max(0, $window), 'verdict' => 'pending', 'before' => null, 'after' => null, 'changes' => [], 'routes' => [], 'new_exceptions' => $newExceptions];
        }

        // Use minute rollups while they are retained; otherwise hour rollups with the
        // windows snapped inwards to whole hours so neither side leaks into the other.
        $minuteRetainedFrom = $now - (config('observability.retention.minute_rollups_hours') - 1) * 3600;
        $step = $at - $window >= $minuteRetainedFrom ? 60 : 3600;
        $edgeBefore = intdiv($at, $step) * $step;
        $edgeAfter = (int) ceil($at / $step) * $step;

        $before = new TimeRange('custom', $edgeBefore - intdiv($window, $step) * $step, $edgeBefore, $step, $edgeBefore);
        $after = new TimeRange('custom', $edgeAfter, $edgeAfter + intdiv($window, $step) * $step, $step, min($now, $edgeAfter + $window));

        $beforeMetrics = RequestReport::totals($before);
        $afterMetrics = RequestReport::totals($after);

        $changes = [
            'p95' => Rollups::change($afterMetrics['p95'], $beforeMetrics['p95']),
            'error_rate' => self::rateChange($afterMetrics['error_rate'], $beforeMetrics['error_rate']),
            'apdex' => $afterMetrics['apdex'] !== null && $beforeMetrics['apdex'] !== null ? round($afterMetrics['apdex'] - $beforeMetrics['apdex'], 3) : null,
            'queries_per_request' => Rollups::change($afterMetrics['queries_per_request'], $beforeMetrics['queries_per_request']),
            'rpm' => Rollups::change($afterMetrics['rpm'], $beforeMetrics['rpm']),
        ];

        $routes = self::routeChanges($before, $after);

        return [
            'window_s' => $window,
            'verdict' => self::verdict($changes, $routes),
            'before' => $beforeMetrics,
            'after' => $afterMetrics,
            'changes' => $changes,
            'routes' => $routes,
            'new_exceptions' => $newExceptions,
        ];
    }

    /**
     * Routes whose p95 or queries-per-request moved materially across the deploy.
     *
     * @return list<array<string, mixed>>
     */
    private static function routeChanges(TimeRange $before, TimeRange $after): array
    {
        $beforeRoutes = collect(RequestReport::routes($before))->keyBy('route');
        $changes = [];

        foreach (RequestReport::routes($after) as $route) {
            $baseline = $beforeRoutes->get($route['route']);

            if ($baseline === null || $baseline['count'] < self::MIN_ROUTE_SAMPLES || $route['count'] < self::MIN_ROUTE_SAMPLES) {
                continue;
            }

            $p95 = Rollups::change($route['p95'], $baseline['p95']);
            $queries = Rollups::change($route['queries_per_request'], $baseline['queries_per_request']);
            $errors = self::rateChange($route['error_rate'], $baseline['error_rate']);

            if (abs($p95 ?? 0) < 0.25 && abs($queries ?? 0) < 0.5 && abs($errors ?? 0) < 0.01) {
                continue;
            }

            $changes[] = [
                'route' => $route['route'],
                'p95_before' => $baseline['p95'],
                'p95_after' => $route['p95'],
                'p95_change' => $p95,
                'queries_before' => $baseline['queries_per_request'],
                'queries_after' => $route['queries_per_request'],
                'queries_change' => $queries,
                'error_rate_before' => $baseline['error_rate'],
                'error_rate_after' => $route['error_rate'],
            ];
        }

        usort($changes, fn (array $a, array $b) => abs($b['p95_change'] ?? 0) <=> abs($a['p95_change'] ?? 0));

        return $changes;
    }

    /**
     * Absolute change in a rate, in fractional points (0.01 = one percentage point).
     */
    private static function rateChange(?float $after, ?float $before): ?float
    {
        return $after === null || $before === null ? null : round($after - $before, 5);
    }

    /**
     * @param  array<string, ?float>  $changes
     * @param  list<array<string, mixed>>  $routes
     */
    private static function verdict(array $changes, array $routes): string
    {
        $routeRegressed = collect($routes)->contains(fn (array $r) => ($r['p95_change'] ?? 0) >= 0.25 || ($r['queries_change'] ?? 0) >= 0.5);
        $routeImproved = collect($routes)->contains(fn (array $r) => ($r['p95_change'] ?? 0) <= -0.25 || ($r['queries_change'] ?? 0) <= -0.5);

        $regressed = ($changes['p95'] ?? 0) >= 0.2
            || ($changes['error_rate'] ?? 0) >= 0.005
            || ($changes['apdex'] ?? 0) <= -0.05
            || ($changes['queries_per_request'] ?? 0) >= 0.25
            || $routeRegressed;

        $improved = ($changes['p95'] ?? 0) <= -0.2
            || ($changes['error_rate'] ?? 0) <= -0.005
            || ($changes['apdex'] ?? 0) >= 0.05
            || $routeImproved;

        return match (true) {
            $regressed => 'regressed',
            $improved => 'improved',
            default => 'neutral',
        };
    }
}
