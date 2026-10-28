<?php

namespace App\Http\Controllers\Ledgerline;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateInvoicePdf;
use App\Jobs\SendInvoiceEmail;
use App\Ledgerline\InvoiceRenderer;
use App\Models\Ledgerline\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $invoices = Invoice::query()
            ->when(config('ledgerline.eager_load'), fn ($query) => $query->with(['customer', 'lines']))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json([
            'data' => $invoices->getCollection()->map(fn (Invoice $invoice) => [
                'number' => $invoice->number,
                'customer' => $invoice->customer->name,
                'status' => $invoice->status,
                'lines' => $invoice->lines->count(),
                'total_cents' => $invoice->total_cents,
                'due_on' => $invoice->due_on->toDateString(),
            ]),
            'total' => $invoices->total(),
        ]);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $invoice->load('customer', 'lines', 'payments');

        return response()->json([
            'number' => $invoice->number,
            'customer' => $invoice->customer->only('id', 'name', 'region'),
            'status' => $invoice->status,
            'lines' => $invoice->lines->map->only('description', 'quantity', 'unit_cents'),
            'paid_cents' => $invoice->payments->sum('amount_cents'),
            'total_cents' => $invoice->total_cents,
        ]);
    }

    public function pdf(Invoice $invoice, InvoiceRenderer $renderer): JsonResponse
    {
        return response()->json($renderer->render($invoice));
    }

    public function send(Invoice $invoice): JsonResponse
    {
        SendInvoiceEmail::dispatch($invoice);
        GenerateInvoicePdf::dispatch($invoice);

        return response()->json(['queued' => true], 202);
    }
}
