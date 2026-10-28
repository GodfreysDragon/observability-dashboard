<?php

namespace App\Jobs;

use App\Models\Ledgerline\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes a payment to the external accounting ledger (simulated network call).
 */
class SyncPaymentToLedger implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public Payment $payment)
    {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        usleep(random_int(20, 120) * 1000);

        $this->payment->forceFill(['synced_at' => now()])->save();
    }
}
