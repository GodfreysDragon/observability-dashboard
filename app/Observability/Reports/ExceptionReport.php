<?php

namespace App\Observability\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ExceptionReport
{
    /**
     * Exception groups seen in the window, with their counts and a per-step trend.
     *
     * @return list<array<string, mixed>>
     */
    public static function groups(TimeRange $range, bool $includeResolved = true): array
    {
        $series = Rollups::aggregate('obs_exception_rollups', $range, ['count'], groupBy: ['group_id'], byTime: true, histogram: false)
            ->groupBy('group_id');

        if ($series->isEmpty()) {
            return [];
        }

        $groups = DB::table('obs_exception_groups')
            ->whereIn('id', $series->keys())
            ->when(! $includeResolved, fn (Builder $query) => $query->whereNull('resolved_at'))
            ->get();

        $previous = Rollups::aggregate('obs_exception_rollups', $range->previous(), ['count'], groupBy: ['group_id'], histogram: false)
            ->pluck('count', 'group_id');

        return $groups
            ->map(function (object $group) use ($range, $series, $previous) {
                $points = Rollups::fill($range, $series[$group->id]);

                return [
                    'id' => $group->id,
                    'class' => $group->class,
                    'message' => $group->message,
                    'location' => $group->file.':'.$group->line,
                    'count' => (int) $series[$group->id]->sum('count'),
                    'previous_count' => (int) ($previous[$group->id] ?? 0),
                    'trend' => array_values(array_map(fn (?object $row) => (int) ($row->count ?? 0), $points)),
                    'first_seen_at' => $group->first_seen_at,
                    'last_seen_at' => $group->last_seen_at,
                    'first_release' => $group->first_release,
                    'is_new' => strtotime($group->first_seen_at.' UTC') >= $range->from,
                    'resolved_at' => $group->resolved_at,
                    'occurrences' => (int) $group->occurrences,
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function detail(TimeRange $range, int $groupId): ?array
    {
        $group = DB::table('obs_exception_groups')->find($groupId);

        if ($group === null) {
            return null;
        }

        $rows = Rollups::aggregate('obs_exception_rollups', $range, ['count'], scope: fn (Builder $query) => $query->where('group_id', $groupId), byTime: true, histogram: false);

        $occurrences = DB::table('obs_exceptions')
            ->leftJoin('obs_requests', 'obs_requests.id', '=', 'obs_exceptions.request_id')
            ->leftJoin('obs_job_runs', 'obs_job_runs.id', '=', 'obs_exceptions.job_run_id')
            ->where('group_id', $groupId)
            ->orderByDesc('obs_exceptions.occurred_at')
            ->limit(25)
            ->get([
                'obs_exceptions.id', 'obs_exceptions.occurred_at', 'obs_exceptions.message', 'obs_exceptions.release',
                'obs_exceptions.request_id', 'obs_requests.route', 'obs_requests.uri', 'obs_requests.status',
                'obs_exceptions.job_run_id', 'obs_job_runs.job', 'obs_job_runs.queue',
            ]);

        $latest = DB::table('obs_exceptions')->where('group_id', $groupId)->orderByDesc('occurred_at')->first(['trace']);

        $origins = DB::table('obs_exceptions')
            ->leftJoin('obs_requests', 'obs_requests.id', '=', 'obs_exceptions.request_id')
            ->leftJoin('obs_job_runs', 'obs_job_runs.id', '=', 'obs_exceptions.job_run_id')
            ->where('group_id', $groupId)
            ->selectRaw("COALESCE(obs_requests.route, obs_job_runs.job, 'console') AS origin, COUNT(*) AS count")
            ->groupByRaw("COALESCE(obs_requests.route, obs_job_runs.job, 'console')")
            ->orderByDesc('count')
            ->get();

        $releases = DB::table('obs_exceptions')
            ->where('group_id', $groupId)
            ->selectRaw('release, COUNT(*) AS count, MIN(occurred_at) AS first_at')
            ->groupBy('release')
            ->orderBy('first_at')
            ->get();

        return [
            'group' => $group,
            'series' => array_map(
                fn (int $bucket, ?object $row) => ['t' => $bucket, 'count' => (int) ($row->count ?? 0)],
                array_keys($filled = Rollups::fill($range, $rows)),
                $filled,
            ),
            'count' => (int) $rows->sum('count'),
            'trace' => $latest ? json_decode($latest->trace, true) : [],
            'occurrences' => $occurrences,
            'origins' => $origins,
            'releases' => $releases,
        ];
    }
}
