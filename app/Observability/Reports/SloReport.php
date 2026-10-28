<?php

namespace App\Observability\Reports;

use App\Observability\Histogram;

/**
 * Availability and latency SLOs over a rolling window, with error-budget accounting
 * and multi-window burn rates, following the Google SRE workbook: page when 2% of the
 * budget burns in 1h, open a ticket when 5% burns in 6h. Over a 30-day window those
 * are the familiar 14.4x and 6x; the thresholds scale with the configured window.
 */
final class SloReport
{
    /** Share of the whole budget that may burn in one hour before it pages. */
    public const float FAST_BURN_BUDGET = 0.02;

    /** Share of the whole budget that may burn in six hours before it opens a ticket. */
    public const float SLOW_BURN_BUDGET = 0.05;

    /**
     * @return array<string, mixed>
     */
    public static function build(?int $now = null): array
    {
        $config = config('observability.slo');
        $now ??= now()->getTimestamp();
        $window = self::window($config['window_days'], $now);
        $lastHour = TimeRange::preset('1h', $now);
        $lastSixHours = TimeRange::preset('6h', $now);
        $thresholds = self::burnThresholds($config['window_days']);

        $availability = fn (TimeRange $range) => self::badRatio($range, fn (object $row) => (float) $row->server_errors);
        $latency = fn (TimeRange $range) => self::badRatio($range, fn (object $row) => $row->count * (1 - (Histogram::fromRow($row)->fractionBelow($config['latency_ms']) ?? 1)));

        return [
            'window_days' => $config['window_days'],
            'latency_ms' => $config['latency_ms'],
            'objectives' => [
                self::objective('availability', 'Requests without a 5xx', $config['availability'], $availability, $window, $lastHour, $lastSixHours, $thresholds),
                self::objective('latency', "Requests faster than {$config['latency_ms']}ms", $config['latency_target'], $latency, $window, $lastHour, $lastSixHours, $thresholds),
            ],
        ];
    }

    /**
     * Burn rates at which the fast (1h) and slow (6h) alerts fire for a window of this length.
     *
     * @return array{fast: float, slow: float}
     */
    public static function burnThresholds(int $windowDays): array
    {
        $windowHours = $windowDays * 24;

        return [
            'fast' => round(self::FAST_BURN_BUDGET * $windowHours, 2),
            'slow' => round(self::SLOW_BURN_BUDGET * $windowHours / 6, 2),
        ];
    }

    /**
     * The rolling SLO window on hour rollups, ending with the hour that contains `$now`.
     */
    private static function window(int $days, int $now): TimeRange
    {
        $to = intdiv($now, 3600) * 3600 + 3600;

        return new TimeRange('slo', $to - $days * 86400, $to, 3600, $now);
    }

    /**
     * @param  callable(TimeRange): array{0: float, 1: int}  $badRatio
     * @param  array{fast: float, slow: float}  $thresholds
     * @return array<string, mixed>
     */
    private static function objective(string $key, string $label, float $target, callable $badRatio, TimeRange $window, TimeRange $lastHour, TimeRange $lastSixHours, array $thresholds): array
    {
        [$bad, $total] = $badRatio($window);
        $allowed = (1 - $target) * $total;
        $burn = function (TimeRange $range) use ($badRatio, $target) {
            [$bad, $total] = $badRatio($range);

            return $total ? round(($bad / $total) / (1 - $target), 2) : null;
        };

        $burnHour = $burn($lastHour);
        $burnSixHours = $burn($lastSixHours);
        $remaining = $allowed > 0 ? round(1 - $bad / $allowed, 4) : null;

        return [
            'key' => $key,
            'label' => $label,
            'target' => $target,
            'attained' => $total ? round(1 - $bad / $total, 5) : null,
            'budget_remaining' => $remaining,
            'burn_rate_1h' => $burnHour,
            'burn_rate_6h' => $burnSixHours,
            'fast_burn_threshold' => $thresholds['fast'],
            'slow_burn_threshold' => $thresholds['slow'],
            'status' => match (true) {
                $remaining !== null && $remaining <= 0 => 'exhausted',
                ($burnHour ?? 0) >= $thresholds['fast'] => 'critical',
                ($burnSixHours ?? 0) >= $thresholds['slow'] => 'warning',
                default => 'ok',
            },
        ];
    }

    /**
     * @param  callable(object): float  $badEvents
     * @return array{0: float, 1: int}
     */
    private static function badRatio(TimeRange $range, callable $badEvents): array
    {
        $row = Rollups::aggregate('obs_request_rollups', $range, ['count', 'server_errors'])->first();

        if (! $row || ! $row->count) {
            return [0.0, 0];
        }

        return [$badEvents($row), (int) $row->count];
    }
}
