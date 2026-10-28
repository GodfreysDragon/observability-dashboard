<?php

namespace App\Observability\Reports;

use InvalidArgumentException;

/**
 * A reporting window: `$points` steps of `$step` seconds ending with the step that
 * contains `$now`. The last point is usually partial, so rates divide by the seconds
 * actually covered (see coveredSeconds()).
 *
 * Ranges up to 24h read minute rollups; the 7-day range reads hour rollups, so no
 * query ever scans more than ~1,500 time buckets per dimension.
 */
final class TimeRange
{
    /** key => [span seconds, step seconds] */
    public const array PRESETS = [
        '1h' => [3600, 60],
        '6h' => [21600, 300],
        '24h' => [86400, 900],
        '7d' => [604800, 3600],
    ];

    public function __construct(
        public readonly string $key,
        public readonly int $from,
        public readonly int $to,
        public readonly int $step,
        public readonly int $now,
    ) {}

    public static function preset(string $key, ?int $now = null): self
    {
        if (! isset(self::PRESETS[$key])) {
            throw new InvalidArgumentException("Unknown range [{$key}].");
        }

        [$span, $step] = self::PRESETS[$key];
        $now ??= now()->getTimestamp();
        $to = intdiv($now, $step) * $step + $step;

        return new self($key, $to - $span, $to, $step, $now);
    }

    /**
     * The same-length window immediately before this one.
     */
    public function previous(): self
    {
        $span = $this->to - $this->from;

        return new self($this->key, $this->from - $span, $this->from, $this->step, $this->from);
    }

    public function period(): int
    {
        return $this->step >= 3600 ? 3600 : 60;
    }

    /**
     * @return list<int>
     */
    public function buckets(): array
    {
        return range($this->from, $this->to - $this->step, $this->step);
    }

    /**
     * Seconds of a bucket that have actually happened (the current one is partial).
     */
    public function coveredSeconds(int $bucket): int
    {
        return max(1, min($this->step, $this->now - $bucket + 1));
    }

    public function totalSeconds(): int
    {
        return max(1, min($this->to, $this->now + 1) - $this->from);
    }

    public function fromDate(): string
    {
        return gmdate('Y-m-d H:i:s', $this->from);
    }

    public function toDate(): string
    {
        return gmdate('Y-m-d H:i:s', $this->to);
    }
}
