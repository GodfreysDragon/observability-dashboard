<?php

namespace App\Observability;

/**
 * A fixed log-scale latency histogram, stored as one integer column per bucket.
 *
 * Bucket i counts durations in (GROWTH^(i-1), GROWTH^i] milliseconds; bucket 0 holds
 * everything up to 1ms and the last bucket is an overflow for anything slower than
 * ~56s. Fixed columns mean rollups merge with plain SQL (`h7 = h7 + excluded.h7`,
 * `SUM(h7)`), so recording needs no read-modify-write and no locks, and any range or
 * route set can be summed in the database before percentiles are estimated here.
 *
 * With GROWTH = 1.25 a bucket spans 25% of its lower bound. Interpolating
 * geometrically within the bucket keeps the typical percentile error to a few
 * percent; the worst case is bounded by the bucket width.
 */
final class Histogram
{
    public const float GROWTH = 1.25;

    public const int BUCKETS = 51;

    /** @var array<int, int> */
    private array $counts;

    /**
     * @param  array<int, int>  $counts
     */
    public function __construct(array $counts = [])
    {
        $this->counts = array_replace(array_fill(0, self::BUCKETS, 0), $counts);
    }

    /**
     * @return list<string>
     */
    public static function columns(string $prefix = 'h'): array
    {
        return array_map(fn (int $i) => $prefix.$i, range(0, self::BUCKETS - 1));
    }

    public static function bucketFor(float $milliseconds): int
    {
        if ($milliseconds <= 1.0) {
            return 0;
        }

        $index = (int) ceil(log($milliseconds) / log(self::GROWTH) - 1e-9);

        return min(self::BUCKETS - 1, max(1, $index));
    }

    /**
     * Upper bound of bucket i in milliseconds (the overflow bucket has none).
     */
    public static function upperBound(int $index): float
    {
        return $index >= self::BUCKETS - 1 ? INF : self::GROWTH ** $index;
    }

    public static function lowerBound(int $index): float
    {
        return $index === 0 ? 0.0 : self::GROWTH ** ($index - 1);
    }

    /**
     * Build from a database row holding SUM()s or raw columns named h0..h50.
     */
    public static function fromRow(array|object $row, string $prefix = 'h'): self
    {
        $row = (array) $row;
        $counts = [];

        foreach (range(0, self::BUCKETS - 1) as $i) {
            $counts[$i] = (int) ($row[$prefix.$i] ?? 0);
        }

        return new self($counts);
    }

    public function record(float $milliseconds, int $times = 1): void
    {
        $this->counts[self::bucketFor($milliseconds)] += $times;
    }

    public function merge(self $other): void
    {
        foreach ($other->counts as $i => $count) {
            $this->counts[$i] += $count;
        }
    }

    public function count(): int
    {
        return array_sum($this->counts);
    }

    /**
     * @return array<int, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * @return array<string, int>
     */
    public function toColumns(string $prefix = 'h'): array
    {
        $columns = [];

        foreach ($this->counts as $i => $count) {
            $columns[$prefix.$i] = $count;
        }

        return $columns;
    }

    /**
     * Estimate the q-quantile (0..1). Returns null for an empty histogram.
     *
     * @param  float|null  $max  the observed maximum, used to cap the estimate and to
     *                           place values in the unbounded overflow bucket
     */
    public function percentile(float $q, ?float $max = null): ?float
    {
        $total = $this->count();

        if ($total === 0) {
            return null;
        }

        $rank = max(1e-9, min(1.0, $q) * $total);
        $seen = 0;

        foreach ($this->counts as $i => $count) {
            if ($count === 0 || $seen + $count < $rank) {
                $seen += $count;

                continue;
            }

            $fraction = ($rank - $seen) / $count;
            $lower = self::lowerBound($i);
            $upper = self::upperBound($i);

            if (is_infinite($upper)) {
                return $max ?? $lower;
            }

            $estimate = $lower > 0
                ? $lower * (($upper / $lower) ** $fraction)
                : $upper * $fraction;

            return $max !== null ? min($estimate, $max) : $estimate;
        }

        return $max;
    }

    /**
     * Share of observations at or below the given duration (interpolated inside the
     * bucket that straddles it). Used for latency SLOs and Apdex-style thresholds.
     */
    public function fractionBelow(float $milliseconds): ?float
    {
        $total = $this->count();

        if ($total === 0) {
            return null;
        }

        $target = self::bucketFor($milliseconds);
        $below = 0;

        foreach ($this->counts as $i => $count) {
            if ($i < $target) {
                $below += $count;
            } elseif ($i === $target) {
                $lower = self::lowerBound($i);
                $upper = self::upperBound($i);
                $share = is_infinite($upper) || $lower <= 0
                    ? ($upper > 0 && ! is_infinite($upper) ? $milliseconds / $upper : 0.0)
                    : log($milliseconds / $lower) / log($upper / $lower);
                $below += $count * max(0.0, min(1.0, $share));
            }
        }

        return $below / $total;
    }
}
