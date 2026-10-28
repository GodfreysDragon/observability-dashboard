<?php

use App\Http\Controllers\Admin\DeployController;
use App\Http\Controllers\Admin\ExceptionController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\QueryController;
use App\Http\Controllers\Admin\QueueController;
use App\Http\Controllers\Admin\RouteController;
use App\Http\Controllers\Admin\TraceController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard JSON API. Lives in the web group so the Vue SPA authenticates with the
| session cookie and CSRF token rather than a long-lived token in the browser.
*/
Route::prefix('api/admin')->name('admin.')->group(function () {
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware('auth')->group(function () {
        Route::get('overview', OverviewController::class)->name('overview');
        Route::get('routes', [RouteController::class, 'index'])->name('routes.index');
        Route::get('routes/detail', [RouteController::class, 'show'])->name('routes.show');
        Route::get('traces', [TraceController::class, 'index'])->name('traces.index');
        Route::get('traces/requests/{id}', [TraceController::class, 'showRequest'])->whereNumber('id')->name('traces.request');
        Route::get('traces/jobs/{id}', [TraceController::class, 'showJob'])->whereNumber('id')->name('traces.job');
        Route::get('queries', [QueryController::class, 'index'])->name('queries.index');
        Route::get('queries/{fingerprint}', [QueryController::class, 'show'])->where('fingerprint', '[0-9a-f]{16}')->name('queries.show');
        Route::get('exceptions', [ExceptionController::class, 'index'])->name('exceptions.index');
        Route::get('exceptions/{group}', [ExceptionController::class, 'show'])->whereNumber('group')->name('exceptions.show');
        Route::put('exceptions/{group}/resolution', [ExceptionController::class, 'resolve'])->whereNumber('group')->name('exceptions.resolve');
        Route::get('queues', QueueController::class)->name('queues');
        Route::get('deploys', DeployController::class)->name('deploys');
    });
});

// Every other path belongs to the Vue router (Ledgerline's routes are registered first).
Route::view('/{any?}', 'app')->where('any', '^(?!api/|up$|ledgerline/).*')->name('app');
