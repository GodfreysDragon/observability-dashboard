<?php

namespace App\Observability\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Horizon-style queue health: throughput, failures, runtime and wait per queue and
 * per job class, plus backlog depth from the per-minute snapshots.
 */
final class QueueReport
{
    public const array SUMS = ['processed', 'failed', 'released', 'runtime_sum', 'waited', 'wait_sum'];

    /**
     * @return array<string, float|int|null>
     */
    public static function metrics(?object $row, int $seconds): array
    {
        $runs = (int) (($row->processed ?? 0) + ($row->failed ?? 0) + ($row->released ?? 0));

        return [
            'processed' => (int) ($row->processed ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'released' => (int) ($row->released ?? 0),
            'jobs_per_minute' => round($runs / max(1, $seconds) * 60, 2),
            'failure_rate' => Rollups::ratio($row->failed ?? 0, $runs),
            'runtime_avg' => Rollups::round($runs ? $row->runtime_sum / $runs : null),
            ...($row && $runs ? Rollups::percentiles($row, 'runtime_max') : ['p50' => null, 'p95' => null, 'p99' => null]),
            'runtime_max' => Rollups::round(isset($row->runtime_max) ? (float) $row->runtime_max : null),
            'wait_avg' => Rollups::round(($row->waited ?? 0) ? $row->wait_sum / $row->waited : null),
            'wait_max' => Rollups::round(isset($row->wait_max) ? (float) $row->wait_max : null),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function queues(TimeRange $range): array
    {
        $rows = Rollups::aggregate('obs_job_rollups', $range, self::SUMS, ['runtime_max', 'wait_max'], groupBy: ['queue'])->keyBy('queue');
        $latest = self::latestSnapshots();
        $names = collect(config('observability.queues'))->merge($rows->keys())->merge($latest->keys())->unique()->values();

        return $names
            ->map(fn (string $queue) => ['queue' => $queue]
                + self::metrics($rows->get($queue), $range->totalSeconds())
                + [
                    'pending' => (int) ($latest[$queue]->pending ?? 0),
                    'delayed' => (int) ($latest[$queue]->delayed ?? 0),
                    'oldest_pending_s' => isset($latest[$queue]->oldest_pending_s) ? (int) $latest[$queue]->oldest_pending_s : null,
                    'snapshot_at' => isset($latest[$queue]) ? (int) $latest[$queue]->taken_at : null,
                ])
            ->all();
    }

    /**
     * Per-step throughput and failures for each queue, and backlog depth.
     *
     * @return array{throughput: array<string, list<array<string, mixed>>>, depth: array<string, list<array{t: int, pending: ?int, oldest_pending_s: ?int}>>}
     */
    public static function series(TimeRange $range): array
    {
        $rows = Rollups::aggregate('obs_job_rollups', $range, self::SUMS, ['runtime_max', 'wait_max'], groupBy: ['queue'], byTime: true)->groupBy('queue');
        $throughput = [];

        foreach ($rows as $queue => $queueRows) {
            foreach (Rollups::fill($range, $queueRows) as $bucket => $row) {
                $metrics = self::metrics($row, $range->coveredSeconds($bucket));
                $throughput[$queue][] = ['t' => $bucket, 'jobs_per_minute' => $metrics['jobs_per_minute'], 'failed' => $metrics['failed'], 'p95' => $metrics['p95'], 'wait_avg' => $metrics['wait_avg']];
            }
        }

        $snapshots = DB::table('obs_queue_snapshots')
            ->where('taken_at', '>=', $range->from)
            ->where('taken_at', '<', $range->to)
            ->selectRaw("queue, (taken_at - taken_at % {$range->step}) AS t, MAX(pending) AS pending, MAX(oldest_pending_s) AS oldest_pending_s")
            ->groupByRaw("queue, (taken_at - taken_at % {$range->step})")
            ->get()
            ->groupBy('queue');

        $depth = [];

        foreach ($snapshots as $queue => $queueRows) {
            foreach (Rollups::fill($range, $queueRows) as $bucket => $row) {
                $depth[$queue][] = [
                    't' => $bucket,
                    'pending' => $row ? (int) $row->pending : null,
                    'oldest_pending_s' => $row && $row->oldest_pending_s !== null ? (int) $row->oldest_pending_s : null,
                ];
            }
        }

        return ['throughput' => $throughput, 'depth' => $depth];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function jobs(TimeRange $range): array
    {
        return Rollups::aggregate('obs_job_rollups', $range, self::SUMS, ['runtime_max', 'wait_max'], groupBy: ['queue', 'job'])
            ->map(fn (object $row) => ['queue' => $row->queue, 'job' => $row->job] + self::metrics($row, $range->totalSeconds()))
            ->sortByDesc(fn (array $row) => $row['processed'] + $row['failed'])
            ->values()
            ->all();
    }

    /**
     * @return list<object>
     */
    public static function recentFailures(TimeRange $range, ?string $queue = null, int $limit = 20): array
    {
        return DB::table('obs_job_runs')
            ->leftJoin('obs_exceptions', 'obs_exceptions.job_run_id', '=', 'obs_job_runs.id')
            ->leftJoin('obs_exception_groups', 'obs_exception_groups.id', '=', 'obs_exceptions.group_id')
            ->whereIn('obs_job_runs.status', ['failed', 'released'])
            ->whereBetween('obs_job_runs.occurred_at', [$range->fromDate(), $range->toDate()])
            ->when($queue, fn (Builder $query) => $query->where('obs_job_runs.queue', $queue))
            ->orderByDesc('obs_job_runs.occurred_at')
            ->limit($limit)
            ->get([
                'obs_job_runs.id', 'obs_job_runs.occurred_at', 'obs_job_runs.queue', 'obs_job_runs.job', 'obs_job_runs.status',
                'obs_job_runs.attempt', 'obs_job_runs.runtime_ms', 'obs_exception_groups.id as group_id',
                'obs_exception_groups.class as exception_class', 'obs_exceptions.message as exception_message',
            ])
            ->all();
    }

    /**
     * @return Collection<string, object>
     */
    private static function latestSnapshots(): Collection
    {
        $latest = DB::table('obs_queue_snapshots')->selectRaw('queue, MAX(taken_at) AS taken_at')->groupBy('queue');

        return DB::table('obs_queue_snapshots')
            ->joinSub($latest, 'latest', fn ($join) => $join->on('latest.queue', '=', 'obs_queue_snapshots.queue')->on('latest.taken_at', '=', 'obs_queue_snapshots.taken_at'))
            ->get(['obs_queue_snapshots.*'])
            ->keyBy('queue');
    }
}
