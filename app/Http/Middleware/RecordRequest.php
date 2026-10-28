<?php

namespace App\Http\Middleware;

use App\Observability\Recorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware that brackets a request for the recorder. Persisting happens in
 * terminate(), which PHP-FPM runs after the response has been flushed to the client.
 */
class RecordRequest
{
    public function __construct(private readonly Recorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->recorder->shouldRecord($request)) {
            $this->recorder->startRequest($request);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->recorder->finishRequest($request, $response);
    }
}
