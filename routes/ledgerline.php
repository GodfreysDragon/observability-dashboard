<?php

use App\Http\Controllers\Ledgerline\CustomerController;
use App\Http\Controllers\Ledgerline\InvoiceController;
use App\Http\Controllers\Ledgerline\PaymentWebhookController;
use App\Http\Controllers\Ledgerline\ReportController;
use Illuminate\Support\Facades\Route;

/*
| Ledgerline: the monitored application. Stateless JSON routes; every request here is
| instrumented by the RecordRequest middleware.
*/

Route::get('invoices', [InvoiceController::class, 'index']);
Route::get('invoices/{invoice:number}', [InvoiceController::class, 'show']);
Route::get('invoices/{invoice:number}/pdf', [InvoiceController::class, 'pdf']);
Route::post('invoices/{invoice:number}/send', [InvoiceController::class, 'send']);
Route::get('customers/{customer}', [CustomerController::class, 'show']);
Route::get('search', [CustomerController::class, 'search']);
Route::get('reports/aging', [ReportController::class, 'aging']);
Route::post('webhooks/payments', PaymentWebhookController::class);
