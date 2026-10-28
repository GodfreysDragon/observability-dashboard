<?php

namespace App\Http\Controllers\Ledgerline;

use App\Http\Controllers\Controller;
use App\Jobs\SyncPaymentToLedger;
use App\Models\Ledgerline\Invoice;
use App\Models\Ledgerline\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentWebhookController extends Controller
{
    /**
     * Payment gateway callback. Idempotent on the gateway reference, because gateways
     * retry deliveries they think timed out.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoice' => ['required', 'string', 'exists:invoices,number'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:64'],
        ]);

        $payment = DB::transaction(function () use ($data) {
            $invoice = Invoice::where('number', $data['invoice'])->lockForUpdate()->firstOrFail();

            $payment = Payment::firstOrCreate(
                ['gateway_ref' => $data['reference']],
                ['invoice_id' => $invoice->id, 'amount_cents' => $data['amount_cents'], 'paid_at' => now()],
            );

            if ($payment->wasRecentlyCreated && $invoice->payments()->sum('amount_cents') >= $invoice->total_cents) {
                $invoice->update(['status' => 'paid']);
            }

            return $payment;
        });

        if ($payment->wasRecentlyCreated) {
            SyncPaymentToLedger::dispatch($payment);
        }

        return response()->json(['received' => true, 'duplicate' => ! $payment->wasRecentlyCreated]);
    }
}
