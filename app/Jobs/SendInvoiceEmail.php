<?php

namespace App\Jobs;

use App\Models\Ledgerline\Invoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Sends an invoice through the (simulated) SMTP relay, which is slow and occasionally
 * refuses connections, so the job retries with backoff.
 */
class SendInvoiceEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(public Invoice $invoice)
    {
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        usleep(random_int(80, 400) * 1000);

        if (random_int(1, 100) <= config('ledgerline.smtp_failure_percent')) {
            throw new RuntimeException('SMTP relay refused connection: 421 4.7.0 Try again later');
        }

        $this->invoice->forceFill(['sent_at' => now(), 'status' => $this->invoice->status === 'draft' ? 'sent' : $this->invoice->status])->save();
    }
}
