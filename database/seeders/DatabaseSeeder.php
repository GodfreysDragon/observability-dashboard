<?php

namespace Database\Seeders;

use App\Models\User;
use App\Observability\Simulation\LedgerlineSimulator;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(LedgerlineSimulator $simulator): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Demo Admin', 'password' => 'password'],
        );

        $this->call(LedgerlineSeeder::class);

        $this->command?->info('Simulating a week of Ledgerline telemetry…');
        $stats = $simulator->run(days: 7, seed: 7);
        $this->command?->info(sprintf('%s requests, %s job attempts, %s traces kept.', number_format($stats['requests']), number_format($stats['jobs']), number_format($stats['kept_requests'] + $stats['kept_jobs'])));
    }
}
