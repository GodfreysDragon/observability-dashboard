<?php

namespace App\Jobs;

use App\Ledgerline\InvoiceRenderer;
use App\Models\Ledgerline\Invoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateInvoicePdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public Invoice $invoice)
    {
        $this->onQueue('pdf');
    }

    public function handle(InvoiceRenderer $renderer): void
    {
        $renderer->render($this->invoice);
    }
}
