<?php

namespace App\Observability;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use WeakMap;

/**
 * The instrumentation entry point. Listeners and middleware feed it events; it keeps
 * the current unit of work (request or job) in memory and persists it once, after
 * the work is done (after the response is sent under PHP-FPM).
 *
 * Two rules hold everywhere in here:
 *  - fail open: a broken telemetry write is logged and swallowed, never surfaced to
 *    the user of the monitored app;
 *  - don't observe yourself: queries issued while persisting are ignored.
 */
final class Recorder
{
    private ?Trace $trace = null;

    private bool $persisting = false;

    /** @var array<string, array{0: string, 1: string}> raw SQL => [normalized, fingerprint] */
    private array $normalized = [];

    /** @var WeakMap<Throwable, true> exceptions already attached to a job trace */
    private WeakMap $seen;

    public function __construct(
        private readonly TraceWriter $writer,
        private readonly Config $config,
    ) {
        $this->seen = new WeakMap;
    }

    public function current(): ?Trace
    {
        return $this->trace;
    }

    public function shouldRecord(Request $request): bool
    {
        return $this->config->get('observability.enabled')
            && ! $request->is(...$this->config->get('observability.ignore_paths'))
            && $request->is(...$this->config->get('observability.record_paths'));
    }

    public function startRequest(Request $request): void
    {
        $this->trace = new Trace('request', ['method' => $request->getMethod()]);
    }

    public function finishRequest(Request $request, Response $response): void
    {
        $trace = $this->trace;
        $this->trace = null;

        if ($trace === null || $trace->kind !== 'request') {
            return;
        }

        $route = $request->route();
        $method = $request->getMethod();

        // Route patterns, not URLs: /invoices/{invoice} is one series however many invoices exist.
        $name = $route !== null ? $method.' /'.ltrim($route->uri(), '/') : $method.' [unmatched]';

        $this->persist(fn () => $this->writer->writeRequest($trace, [
            'method' => $method,
            'route' => $name,
            'uri' => mb_substr('/'.ltrim($request->path(), '/'), 0, 500),
            'status' => $response->getStatusCode(),
            'duration_ms' => $trace->elapsedMs(),
            'memory_mb' => memory_get_peak_usage(true) / 1048576,
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]));
    }

    /**
     * Jobs run inline by the sync driver belong to the request that dispatched them,
     * so a job trace only starts when no other work is being recorded.
     *
     * @param  array<string, mixed>  $meta
     */
    public function startJob(array $meta): bool
    {
        if (! $this->config->get('observability.enabled') || ($this->trace !== null && $this->trace->kind === 'request')) {
            return false;
        }

        if ($this->trace !== null) {
            $this->finishJob('released');
        }

        $this->trace = new Trace('job', $meta);

        return true;
    }

    public function markJobFailed(): void
    {
        if ($this->trace?->kind === 'job') {
            $this->trace->meta['failed'] = true;
        }
    }

    /**
     * @param  'processed'|'failed'|'released'  $status
     */
    public function finishJob(string $status): void
    {
        $trace = $this->trace;

        if ($trace === null || $trace->kind !== 'job') {
            return;
        }

        $this->trace = null;

        if (! empty($trace->meta['failed'])) {
            $status = 'failed';
        }

        $this->persist(fn () => $this->writer->writeJob($trace, $status, $trace->elapsedMs()));
    }

    public function recordQuery(QueryExecuted $event): void
    {
        if ($this->persisting || $this->trace === null) {
            return;
        }

        [$sql, $fingerprint] = $this->normalized[$event->sql] ??= (function () use ($event) {
            $normalized = SqlNormalizer::normalize($event->sql);

            return [$normalized, SqlNormalizer::fingerprint($normalized)];
        })();

        if (count($this->normalized) > 2000) {
            $this->normalized = [];
        }

        $this->trace->addQuery($fingerprint, $sql, (float) $event->time, $this->config->get('observability.query_locations') ? $this->location() : null);
    }

    /**
     * Called from the exception handler's report hook (and for job exceptions, from the
     * worker events, which fire before the handler gets to report them).
     */
    public function recordException(Throwable $exception): void
    {
        if ($this->persisting || ! $this->config->get('observability.enabled') || isset($this->seen[$exception])) {
            return;
        }

        $this->seen[$exception] = true;

        if ($this->trace !== null) {
            $this->trace->exceptions[] = $exception;

            return;
        }

        // Outside any request or job (a console command, the scheduler): store it alone.
        $this->persist(fn () => $this->writer->writeStandaloneException($exception));
    }

    private function persist(callable $write): void
    {
        $this->persisting = true;

        try {
            $write();
        } catch (Throwable $e) {
            Log::warning('Observability write failed: '.$e->getMessage(), ['exception' => $e::class]);
        } finally {
            $this->persisting = false;
        }
    }

    /**
     * The first application frame that issued the query (vendor and this package skipped).
     */
    private function location(): ?string
    {
        $base = str_replace('\\', '/', base_path()).'/';

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $file = str_replace('\\', '/', $frame['file'] ?? '');

            if ($file === '' || preg_match('~/(vendor|app/Observability)/|/app/Http/Middleware/RecordRequest.php$|/public/index.php$|/artisan$~', $file)) {
                continue;
            }

            return str_replace($base, '', $file).':'.($frame['line'] ?? 0);
        }

        return null;
    }
}
