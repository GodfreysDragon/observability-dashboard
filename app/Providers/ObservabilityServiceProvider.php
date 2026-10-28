<?php

namespace App\Providers;

use App\Observability\ExceptionGrouper;
use App\Observability\Recorder;
use App\Observability\RollupWriter;
use App\Observability\TraceWriter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TraceWriter::class, fn ($app) => new TraceWriter(
            new RollupWriter,
            new ExceptionGrouper($app->basePath()),
            $app->make(CacheFactory::class),
            $app['config'],
        ));

        // One recorder per process; it resets itself between requests and jobs.
        $this->app->singleton(Recorder::class, fn ($app) => new Recorder(
            $app->make(TraceWriter::class),
            $app['config'],
        ));
    }

    public function boot(): void
    {
        if (! config('observability.enabled')) {
            return;
        }

        $recorder = $this->app->make(Recorder::class);

        DB::listen($recorder->recordQuery(...));

        // Stamp enqueue time with sub-second precision so queue wait can be measured.
        Queue::createPayloadUsing(fn () => ['obs_queued_at' => microtime(true)]);

        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($recorder) {
            $payload = $event->job->payload();

            $recorder->startJob([
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue() ?? 'default',
                'job' => $event->job->resolveName(),
                'uuid' => $event->job->uuid(),
                'attempt' => $event->job->attempts(),
                'queued_at' => isset($payload['obs_queued_at']) ? (float) $payload['obs_queued_at'] : null,
            ]);
        });

        Event::listen(JobProcessed::class, fn () => $recorder->finishJob('processed'));

        // JobFailed fires inside the worker's exception handling, before JobExceptionOccurred.
        Event::listen(JobFailed::class, fn () => $recorder->markJobFailed());

        // The worker reports job exceptions only after these events, so capture them here.
        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) use ($recorder) {
            $recorder->recordException($event->exception);

            if ($event->job->hasFailed()) {
                $recorder->finishJob('failed');
            }
        });

        Event::listen(JobReleasedAfterException::class, fn () => $recorder->finishJob('released'));
    }
}
