<?php

use App\Http\Middleware\RecordRequest;
use App\Observability\Recorder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')->prefix('ledgerline')->group(base_path('routes/ledgerline.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Outermost, so the measured duration covers the whole middleware stack.
        $middleware->prepend(RecordRequest::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Telemetry must never take down error reporting itself, so this hook swallows its own failures.
        $exceptions->report(function (Throwable $e) {
            try {
                app(Recorder::class)->recordException($e);
            } catch (Throwable) {
                //
            }
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'ledgerline/*') || $request->expectsJson(),
        );
    })->create();
