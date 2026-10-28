<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

#[Signature('obs:prune')]
#[Description('Delete telemetry older than the configured retention (scheduled hourly)')]
class PruneTelemetry extends Command
{
    /** Deleting in batches keeps each statement's locks short on a busy MySQL primary. */
    private const int BATCH = 5000;

    public function handle(): int
    {
        $retention = config('observability.retention');
        $now = now()->getTimestamp();
        $traceCutoff = gmdate('Y-m-d H:i:s', $now - $retention['traces_hours'] * 3600);

        $deleted = [
            'minute rollups' => $this->rollups(60, $now - $retention['minute_rollups_hours'] * 3600),
            'hour rollups' => $this->rollups(3600, $now - $retention['hour_rollups_days'] * 86400),
            'exception occurrences' => $this->batched('obs_exceptions', 'occurred_at', gmdate('Y-m-d H:i:s', $now - $retention['exceptions_days'] * 86400)),
            'queries' => $this->batched('obs_queries', 'occurred_at', $traceCutoff),
            'request traces' => $this->batched('obs_requests', 'occurred_at', $traceCutoff),
            'job runs' => $this->batched('obs_job_runs', 'occurred_at', $traceCutoff),
            'queue snapshots' => $this->batched('obs_queue_snapshots', 'taken_at', $now - $retention['snapshots_days'] * 86400),
        ];

        foreach ($deleted as $what => $count) {
            $this->components->twoColumnDetail($what, number_format($count));
        }

        return self::SUCCESS;
    }

    private function rollups(int $period, int $before): int
    {
        $total = 0;

        foreach (['obs_request_rollups', 'obs_query_rollups', 'obs_job_rollups', 'obs_exception_rollups'] as $table) {
            $total += $this->batched($table, 'bucket', $before, fn (Builder $query) => $query->where('period', $period));
        }

        return $total;
    }

    private function batched(string $table, string $column, int|string $before, ?callable $scope = null): int
    {
        $total = 0;

        do {
            $ids = DB::table($table)->where($column, '<', $before)->when($scope, $scope)->limit(self::BATCH)->pluck('id');
            $total += $ids->isEmpty() ? 0 : DB::table($table)->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::BATCH);

        return $total;
    }
}
