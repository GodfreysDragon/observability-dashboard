<?php

namespace App\Observability\Reports;

use App\Observability\Histogram;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Query helpers over the rollup tables. Every aggregate is computed in SQL (SUM of the
 * additive columns and of each histogram bucket, MAX of the max columns); PHP only
 * turns the summed histogram into percentiles.
 */
final class Rollups
{
    /**
     * @param  list<string>  $sums
     * @param  list<string>  $max
     * @param  (Closure(Builder): void)|null  $scope
     * @param  list<string>  $groupBy  dimension columns to group by besides time
     * @return Collection<int, object>
     */
    public static function aggregate(
        string $table,
        TimeRange $range,
        array $sums,
        array $max = [],
        ?Closure $scope = null,
        array $groupBy = [],
        bool $byTime = false,
        bool $histogram = true,
    ): Collection {
        $query = DB::table($table)
            ->where('period', $range->period())
            ->where('bucket', '>=', $range->from)
            ->where('bucket', '<', $range->to);

        if ($scope) {
            $scope($query);
        }

        $select = [];

        if ($byTime) {
            $select[] = "(bucket - bucket % {$range->step}) AS t";
        }

        foreach ($groupBy as $column) {
            $select[] = $column;
        }

        foreach ($sums as $column) {
            $select[] = "SUM({$column}) AS {$column}";
        }

        foreach ($max as $column) {
            $select[] = "MAX({$column}) AS {$column}";
        }

        if ($histogram) {
            foreach (Histogram::columns() as $column) {
                $select[] = "SUM({$column}) AS {$column}";
            }
        }

        $query->selectRaw(implode(', ', $select));

        if ($byTime) {
            $query->groupByRaw("(bucket - bucket % {$range->step})");
        }

        if ($groupBy !== []) {
            $query->groupBy($groupBy);
        }

        return $query->get();
    }

    /**
     * Index rows by their time bucket and yield one entry per bucket in the range,
     * with null for buckets that have no data.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, object|null>
     */
    public static function fill(TimeRange $range, Collection $rows): array
    {
        $indexed = $rows->keyBy(fn (object $row) => (int) $row->t);
        $filled = [];

        foreach ($range->buckets() as $bucket) {
            $filled[$bucket] = $indexed->get($bucket);
        }

        return $filled;
    }

    /**
     * Percentile summary for a summed row. Values are rounded for transport.
     *
     * @return array{p50: ?float, p95: ?float, p99: ?float}
     */
    public static function percentiles(object $row, string $maxColumn): array
    {
        $histogram = Histogram::fromRow($row);
        $max = isset($row->{$maxColumn}) ? (float) $row->{$maxColumn} : null;

        return [
            'p50' => self::round($histogram->percentile(0.50, $max)),
            'p95' => self::round($histogram->percentile(0.95, $max)),
            'p99' => self::round($histogram->percentile(0.99, $max)),
        ];
    }

    public static function round(?float $value, int $precision = 1): ?float
    {
        return $value === null ? null : round($value, $precision);
    }

    public static function ratio(float|int|null $part, float|int|null $whole, int $precision = 5): ?float
    {
        return $whole ? round((float) $part / $whole, $precision) : null;
    }

    /**
     * Fractional change from previous to current, or null when there is no baseline.
     */
    public static function change(?float $current, ?float $previous): ?float
    {
        if ($current === null || ! $previous) {
            return null;
        }

        return round(($current - $previous) / $previous, 4);
    }
}
