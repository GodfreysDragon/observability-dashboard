<?php

namespace App\Http\Controllers\Ledgerline;

use App\Http\Controllers\Controller;
use App\Models\Ledgerline\Customer;
use App\Models\Ledgerline\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function show(Customer $customer): JsonResponse
    {
        $invoices = $customer->invoices()->withSum('payments', 'amount_cents')->orderByDesc('issued_on')->limit(50)->get();

        return response()->json([
            'name' => $customer->name,
            'region' => $customer->region,
            'invoices' => $invoices->count(),
            'billed_cents' => $invoices->sum('total_cents'),
            'paid_cents' => (int) $invoices->sum('payments_sum_amount_cents'),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $term = addcslashes((string) $request->query('q', ''), '\\%_');

        return response()->json([
            'customers' => Customer::query()->where('name', 'like', "%{$term}%")->limit(10)->pluck('name'),
            'invoices' => Invoice::query()->where('number', 'like', "{$term}%")->limit(10)->pluck('number'),
        ]);
    }
}
