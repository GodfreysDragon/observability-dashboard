<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Business data for the monitored app, so its live routes have something to read.
 * Bulk inserts with a fixed seed: ~300 customers, ~3,000 invoices, ~10,000 lines.
 */
class LedgerlineSeeder extends Seeder
{
    public function run(int $customers = 300, int $invoices = 3000): void
    {
        mt_srand(2026);
        $now = Carbon::now();
        $regions = ['UK', 'UK', 'UK', 'IE', 'DE', 'FR', 'NL', 'US'];
        $names = ['Acme', 'Northwind', 'Globex', 'Initech', 'Umbrella', 'Stark', 'Wayne', 'Hooli', 'Vandelay', 'Soylent', 'Tyrell', 'Cyberdyne', 'Wonka', 'Gringotts', 'Monarch'];
        $suffixes = ['Ltd', 'GmbH', 'BV', 'SARL', 'Inc', 'Holdings', 'Partners', 'Group'];

        $rows = [];

        for ($i = 1; $i <= $customers; $i++) {
            $rows[] = [
                'name' => $names[mt_rand(0, count($names) - 1)].' '.$suffixes[mt_rand(0, count($suffixes) - 1)].' #'.$i,
                'email' => "billing{$i}@example.test",
                // Every 40th account is a legacy import with the unmapped "XI" region.
                'region' => $i % 40 === 0 ? 'XI' : $regions[mt_rand(0, count($regions) - 1)],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('customers')->insert($chunk);
        }

        $descriptions = ['Consulting', 'Support plan', 'Hosting', 'Licence seat', 'Onboarding', 'Training day'];
        $invoiceRows = [];
        $lineRows = [];
        $paymentRows = [];

        for ($i = 1; $i <= $invoices; $i++) {
            $issued = $now->copy()->subDays(mt_rand(0, 180));
            $lines = mt_rand(1, 6);
            $total = 0;

            for ($l = 0; $l < $lines; $l++) {
                $quantity = mt_rand(1, 12);
                $unit = mt_rand(20, 900) * 100;
                $total += $quantity * $unit;
                $lineRows[] = ['invoice_id' => $i, 'description' => $descriptions[mt_rand(0, 5)], 'quantity' => $quantity, 'unit_cents' => $unit];
            }

            $due = $issued->copy()->addDays(30);
            $status = match (true) {
                mt_rand(1, 100) <= 55 => 'paid',
                $due->isPast() => 'overdue',
                mt_rand(1, 10) === 1 => 'draft',
                default => 'sent',
            };

            $invoiceRows[] = [
                'customer_id' => mt_rand(1, $customers),
                'number' => sprintf('INV-%06d', 100000 + $i),
                'status' => $status,
                'issued_on' => $issued->toDateString(),
                'due_on' => $due->toDateString(),
                'total_cents' => $total,
                'sent_at' => $status === 'draft' ? null : $issued,
                'created_at' => $issued,
                'updated_at' => $issued,
            ];

            if ($status === 'paid') {
                $paymentRows[] = ['invoice_id' => $i, 'amount_cents' => $total, 'gateway_ref' => sprintf('ch_seed_%06d', $i), 'paid_at' => $issued->copy()->addDays(mt_rand(1, 40)), 'synced_at' => $now];
            }
        }

        foreach ([['invoices', $invoiceRows], ['invoice_lines', $lineRows], ['payments', $paymentRows]] as [$table, $data]) {
            foreach (array_chunk($data, 300) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
    }
}
