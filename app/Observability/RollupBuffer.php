<?php

namespace App\Observability;

/**
 * Accumulates rollup increments in memory, keyed by (table, period, bucket, dimension),
 * so one flush writes each row once. A live request contributes a handful of rows; the
 * simulator pushes an hour of synthetic traffic through the same buffer.
 *
 * Every observation lands in both resolutions: minute rows for short ranges, hour
 * rows for long ranges and deploy comparisons.
 */
final class RollupBuffer
{
    public const array PERIODS = [60, 3600];

    /**
     * Additive and max columns per table, so every row in a batch has the same columns.
     *
     * @var array<string, array{sums: list<string>, max: list<string>, histogram: bool}>
     */
    private const array COLUMNS = [
        'obs_request_rollups' => [
            'sums' => ['count', 'server_errors', 'client_errors', 'satisfied', 'tolerating', 'duration_sum', 'query_count_sum', 'query_ms_sum', 'n_plus_one'],
            'max' => ['duration_max'],
            'histogram' => true,
        ],
        'obs_query_rollups' => [
            'sums' => ['calls', 'total_ms', 'n_plus_one'],
            'max' => ['max_ms'],
            'histogram' => true,
        ],
        'obs_job_rollups' => [
            'sums' => ['processed', 'failed', 'released', 'runtime_sum', 'waited', 'wait_sum'],
            'max' => ['runtime_max', 'wait_max'],
            'histogram' => true,
        ],
        'obs_exception_rollups' => [
            'sums' => ['count'],
            'max' => [],
            'histogram' => false,
        ],
    ];

    /**
     * @return array{sums: list<string>, max: list<string>, histogram: bool}
     */
    public static function columnsFor(string $table): array
    {
        return self::COLUMNS[$table];
    }

    /** @var array<string, array<string, array{keys: array<string, int|string>, sums: array<string, float|int>, max: array<string, float>, histogram: ?Histogram}>> */
    private array $rows = [];

    /**
     * @param  list<int>  $periods  resolutions to write; backfills skip minute rows that would already be pruned
     */
    public function __construct(
        private readonly int $apdexMs = 300,
        private readonly array $periods = self::PERIODS,
    ) {}

    public function addRequest(
        int $timestamp,
        string $route,
        int $status,
        float $durationMs,
        int $queryCount,
        float $queryMs,
        bool $nPlusOne,
        int $weight = 1,
    ): void {
        $serverError = $status >= 500;

        $this->add('obs_request_rollups', $timestamp, ['route' => $route], [
            'count' => $weight,
            'server_errors' => $serverError ? $weight : 0,
            'client_errors' => $status >= 400 && ! $serverError ? $weight : 0,
            'satisfied' => ! $serverError && $durationMs <= $this->apdexMs ? $weight : 0,
            'tolerating' => ! $serverError && $durationMs > $this->apdexMs && $durationMs <= 4 * $this->apdexMs ? $weight : 0,
            'duration_sum' => $durationMs * $weight,
            'query_count_sum' => $queryCount * $weight,
            'query_ms_sum' => $queryMs * $weight,
            'n_plus_one' => $nPlusOne ? $weight : 0,
        ], ['duration_max' => $durationMs], $durationMs, $weight);
    }

    public function addQuery(int $timestamp, string $fingerprint, float $durationMs, int $weight = 1): void
    {
        $this->add('obs_query_rollups', $timestamp, ['fingerprint' => $fingerprint], [
            'calls' => $weight,
            'total_ms' => $durationMs * $weight,
        ], ['max_ms' => $durationMs], $durationMs, $weight);
    }

    /**
     * Count one request or job in which this fingerprint looked like an N+1.
     */
    public function addNPlusOne(int $timestamp, string $fingerprint, int $weight = 1): void
    {
        $this->add('obs_query_rollups', $timestamp, ['fingerprint' => $fingerprint], ['n_plus_one' => $weight]);
    }

    /**
     * @param  'processed'|'failed'|'released'  $status
     */
    public function addJob(
        int $timestamp,
        string $queue,
        string $job,
        string $status,
        float $runtimeMs,
        ?float $waitMs,
        int $weight = 1,
    ): void {
        $this->add('obs_job_rollups', $timestamp, ['queue' => $queue, 'job' => $job], [
            'processed' => $status === 'processed' ? $weight : 0,
            'failed' => $status === 'failed' ? $weight : 0,
            'released' => $status === 'released' ? $weight : 0,
            'runtime_sum' => $runtimeMs * $weight,
            'waited' => $waitMs === null ? 0 : $weight,
            'wait_sum' => ($waitMs ?? 0) * $weight,
        ], ['runtime_max' => $runtimeMs, 'wait_max' => $waitMs ?? 0.0], $runtimeMs, $weight);
    }

    public function addException(int $timestamp, int $groupId, int $weight = 1): void
    {
        $this->add('obs_exception_rollups', $timestamp, ['group_id' => $groupId], ['count' => $weight]);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * Rows ready for an upsert, grouped by table. Each row has every column so a batch
     * insert gets a consistent column list.
     *
     * @return array<string, list<array<string, int|float|string>>>
     */
    public function rows(): array
    {
        $tables = [];

        foreach ($this->rows as $table => $rows) {
            foreach ($rows as $row) {
                $tables[$table][] = $row['keys'] + $row['sums'] + $row['max'] + ($row['histogram']?->toColumns() ?? []);
            }
        }

        return $tables;
    }

    public function clear(): void
    {
        $this->rows = [];
    }

    /**
     * @param  array<string, int|string>  $dimensions
     * @param  array<string, float|int>  $sums
     * @param  array<string, float>  $max
     */
    private function add(
        string $table,
        int $timestamp,
        array $dimensions,
        array $sums,
        array $max = [],
        ?float $histogramValue = null,
        int $weight = 1,
    ): void {
        foreach ($this->periods as $period) {
            $bucket = $timestamp - ($timestamp % $period);
            $key = $period.'|'.$bucket.'|'.implode('|', $dimensions);

            $row = &$this->rows[$table][$key];

            if ($row === null) {
                $row = [
                    'keys' => ['period' => $period, 'bucket' => $bucket] + $dimensions,
                    'sums' => array_fill_keys(self::COLUMNS[$table]['sums'], 0),
                    'max' => array_fill_keys(self::COLUMNS[$table]['max'], 0.0),
                    'histogram' => self::COLUMNS[$table]['histogram'] ? new Histogram : null,
                ];
            }

            foreach ($sums as $column => $value) {
                $row['sums'][$column] += $value;
            }

            foreach ($max as $column => $value) {
                $row['max'][$column] = max($row['max'][$column], $value);
            }

            if ($histogramValue !== null) {
                $row['histogram']->record($histogramValue, $weight);
            }

            unset($row);
        }
    }
}
