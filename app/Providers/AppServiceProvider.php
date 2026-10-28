<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rollup GROUP BYs need temp B-trees. `artisan serve` strips TEMP/TMP from its worker
        // environment, so on Windows SQLite cannot create temp files; keep them in memory.
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            if ($event->connection->getDriverName() === 'sqlite') {
                $event->connection->statement('PRAGMA temp_store = MEMORY');
            }
        });

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
