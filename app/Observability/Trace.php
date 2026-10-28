<?php

namespace App\Observability;

use Throwable;

/**
 * Everything captured during one unit of work (an HTTP request or a queued job),
 * held in memory until the work finishes and the recorder decides what to keep.
 */
final class Trace
{
    public const int MAX_STORED_QUERIES = 500;

    /** @var list<array{fingerprint: string, sql: string, duration_ms: float, offset_ms: float, location: ?string, select: bool}> */
    public array $queries = [];

    public int $queryCount = 0;

    public float $queryMs = 0.0;

    /** @var list<Throwable> */
    public array $exceptions = [];

    public readonly float $startedAt;

    private readonly int $startedHr;

    /**
     * @param  'request'|'job'  $kind
     * @param  array<string, mixed>  $meta
     */
    public function __construct(public readonly string $kind, public array $meta = [])
    {
        $this->startedAt = microtime(true);
        $this->startedHr = hrtime(true);
    }

    public function elapsedMs(): float
    {
        return (hrtime(true) - $this->startedHr) / 1e6;
    }

    public function addQuery(string $fingerprint, string $sql, float $durationMs, ?string $location): void
    {
        $this->queryCount++;
        $this->queryMs += $durationMs;

        if (count($this->queries) >= self::MAX_STORED_QUERIES) {
            return;
        }

        $this->queries[] = [
            'fingerprint' => $fingerprint,
            'sql' => $sql,
            'duration_ms' => $durationMs,
            'offset_ms' => max(0.0, $this->elapsedMs() - $durationMs),
            'location' => $location,
            'select' => SqlNormalizer::isSelect($sql),
        ];
    }

    /**
     * Fingerprints of SELECTs repeated at least `$threshold` times: the classic N+1
     * signature of a relation loaded inside a loop.
     *
     * @return array<string, int> fingerprint => executions
     */
    public function nPlusOneFingerprints(int $threshold): array
    {
        $counts = [];

        foreach ($this->queries as $query) {
            if ($query['select']) {
                $counts[$query['fingerprint']] = ($counts[$query['fingerprint']] ?? 0) + 1;
            }
        }

        return array_filter($counts, fn (int $count) => $count >= $threshold);
    }

    public function slowestQueryMs(): float
    {
        return $this->queries === [] ? 0.0 : max(array_column($this->queries, 'duration_ms'));
    }
}
