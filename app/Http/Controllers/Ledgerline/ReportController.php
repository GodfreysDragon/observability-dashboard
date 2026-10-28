<?php

namespace App\Http\Controllers\Ledgerline;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Receivables aging: unpaid balance per customer bucketed by days overdue. The
     * cut-off dates are bound parameters so the SQL stays portable across databases.
     */
    public function aging(): JsonResponse
    {
        $today = now()->toDateString();
        $d30 = now()->subDays(30)->toDateString();
        $d60 = now()->subDays(60)->toDateString();
        $d90 = now()->subDays(90)->toDateString();
        $balance = 'invoices.total_cents - COALESCE(paid.paid, 0)';

        $paid = DB::table('payments')->select('invoice_id', DB::raw('SUM(amount_cents) AS paid'))->groupBy('invoice_id');

        $rows = DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoinSub($paid, 'paid', 'paid.invoice_id', '=', 'invoices.id')
            ->where('invoices.status', '!=', 'draft')
            ->selectRaw('customers.name AS customer')
            ->selectRaw("SUM(CASE WHEN invoices.due_on >= ? THEN {$balance} ELSE 0 END) AS current_cents", [$today])
            ->selectRaw("SUM(CASE WHEN invoices.due_on < ? AND invoices.due_on >= ? THEN {$balance} ELSE 0 END) AS d1_30", [$today, $d30])
            ->selectRaw("SUM(CASE WHEN invoices.due_on < ? AND invoices.due_on >= ? THEN {$balance} ELSE 0 END) AS d31_60", [$d30, $d60])
            ->selectRaw("SUM(CASE WHEN invoices.due_on < ? AND invoices.due_on >= ? THEN {$balance} ELSE 0 END) AS d61_90", [$d60, $d90])
            ->selectRaw("SUM(CASE WHEN invoices.due_on < ? THEN {$balance} ELSE 0 END) AS d90_plus", [$d90])
            ->groupBy('customers.id', 'customers.name')
            ->orderByDesc('d90_plus')
            ->limit(50)
            ->get();

        return response()->json(['rows' => $rows]);
    }
}
