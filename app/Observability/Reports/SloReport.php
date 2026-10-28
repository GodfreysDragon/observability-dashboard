<?php

namespace App\Observability\Reports;

use App\Observability\Histogram;

/**
 * Availability and latency SLOs over a rolling window, with error-budget accounting
 * and multi-window burn rates (the 14.4x / 6x thresholds from the Google SRE workbook:
 * at 14.4x a 30-day budget is gone in ~2 days, so it pages; 6x over 6h opens a ticket).
 */
final class SloReport
{
    public const float FAST_BURN = 14.4;

    public const float SLOW_BURN = 6.0;

    /**
     * @return array<string, mixed>
     */
    public static function build(?int $now = null): array
    {
        $config = config('observability.slo');
        $window = TimeRange::preset('7d', $now);
        $lastHour = TimeRange::preset('1h', $now);
        $lastSixHours = TimeRange::preset('6h', $now);

        $availability = fn (TimeRange $range) => self::badRatio($range, fn (object $row) => (float) $row->server_errors);
        $latency = fn (TimeRange $range) => self::badRatio($range, fn (object $row) => $row->count * (1 - (Histogram::fromRow($row)->fractionBelow($config['latency_ms']) ?? 1)));

        return [
            'window_days' => $config['window_days'],
            'latency_ms' => $config['latency_ms'],
            'objectives' => [
                self::objective('availability', 'Requests without a 5xx', $config['availability'], $availability, $window, $lastHour, $lastSixHours),
                self::objective('latency', "Requests faster than {$config['latency_ms']}ms", $config['latency_target'], $latency, $window, $lastHour, $lastSixHours),
            ],
        ];
    }

    /**
     * @param  callable(TimeRange): array{0: float, 1: int}  $badRatio
     * @return array<string, mixed>
     */
    private static function objective(string $key, string $label, float $target, callable $badRatio, TimeRange $window, TimeRange $lastHour, TimeRange $lastSixHours): array
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
            'status' => match (true) {
                $remaining !== null && $remaining <= 0 => 'exhausted',
                ($burnHour ?? 0) >= self::FAST_BURN => 'critical',
                ($burnSixHours ?? 0) >= self::SLOW_BURN => 'warning',
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
