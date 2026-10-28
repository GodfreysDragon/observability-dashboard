<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

#[Signature('obs:traffic {--url= : Base URL of the running app (defaults to APP_URL)} {--minutes=5 : How long to run} {--rpm=120 : Target requests per minute} {--concurrency=4}')]
#[Description('Send real HTTP traffic to Ledgerline so the dashboard fills with live, instrumented data')]
class GenerateTraffic extends Command
{
    public function handle(): int
    {
        $base = rtrim($this->option('url') ?: config('app.url'), '/');
        $invoices = DB::table('invoices')->pluck('number')->all();
        $customers = DB::table('customers')->pluck('id')->all();

        if ($invoices === []) {
            $this->components->error('No Ledgerline data. Run php artisan db:seed first.');

            return self::FAILURE;
        }

        $concurrency = max(1, (int) $this->option('concurrency'));
        $interval = 60 / max(1, (int) $this->option('rpm')) * $concurrency;
        $until = microtime(true) + (float) $this->option('minutes') * 60;
        $sent = 0;
        $statuses = [];

        $this->components->info("Sending traffic to {$base}/ledgerline for {$this->option('minutes')} min. Ctrl+C to stop.");

        while (microtime(true) < $until) {
            $started = microtime(true);
            $batch = array_map(fn () => $this->pick($invoices, $customers), range(1, $concurrency));

            try {
                $responses = Http::pool(fn (Pool $pool) => array_map(
                    fn (array $request) => $pool->acceptJson()->timeout(30)->send($request[0], $base.$request[1], $request[2] ? ['json' => $request[2]] : []),
                    $batch,
                ));

                foreach ($responses as $response) {
                    $status = $response instanceof Throwable ? 'error' : (string) $response->status();
                    $statuses[$status] = ($statuses[$status] ?? 0) + 1;
                    $sent++;
                }
            } catch (Throwable $e) {
                $this->components->warn($e->getMessage());
            }

            $this->output->write("\r  sent {$sent} · ".collect($statuses)->map(fn ($n, $s) => "{$s}: {$n}")->implode(' · ').'   ');
            usleep((int) max(0, ($interval - (microtime(true) - $started)) * 1e6));
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $invoices
     * @param  list<int>  $customers
     * @return array{0: string, 1: string, 2: ?array<string, mixed>}
     */
    private function pick(array $invoices, array $customers): array
    {
        $invoice = $invoices[array_rand($invoices)];
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 22 => ['GET', '/ledgerline/invoices?page='.mt_rand(1, 20), null],
            $roll <= 47 => ['GET', "/ledgerline/invoices/{$invoice}", null],
            $roll <= 55 => ['GET', "/ledgerline/invoices/{$invoice}/pdf", null],
            $roll <= 60 => ['POST', "/ledgerline/invoices/{$invoice}/send", []],
            $roll <= 72 => ['GET', '/ledgerline/customers/'.$customers[array_rand($customers)], null],
            $roll <= 84 => ['GET', '/ledgerline/search?q='.Str::random(2), null],
            $roll <= 86 => ['GET', '/ledgerline/reports/aging', null],
            $roll <= 96 => ['POST', '/ledgerline/webhooks/payments', ['invoice' => $invoice, 'amount_cents' => mt_rand(100, 50000), 'reference' => 'ch_live_'.Str::lower(Str::random(16))]],
            default => ['GET', '/ledgerline/export.csv', null],
        };
    }
}
