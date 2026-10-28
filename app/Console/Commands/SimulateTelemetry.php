<?php

namespace App\Console\Commands;

use App\Observability\Simulation\LedgerlineSimulator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('obs:simulate {--days=7 : Days of history to generate} {--seed=7 : Random seed} {--force : Skip the confirmation}')]
#[Description('Replace all telemetry with a simulated week of Ledgerline traffic, deploys and incidents')]
class SimulateTelemetry extends Command
{
    public function handle(LedgerlineSimulator $simulator): int
    {
        if (! $this->option('force') && ! $this->confirm('This deletes all recorded telemetry (obs_* tables). Continue?', true)) {
            return self::FAILURE;
        }

        $days = max(1, min(14, (int) $this->option('days')));
        $bar = $this->output->createProgressBar($days * 24);
        $started = microtime(true);

        $stats = $simulator->run($days, (int) $this->option('seed'), progress: function (int $done) use ($bar) {
            $bar->setProgress($done);
        });

        $bar->finish();
        $this->newLine(2);

        $this->components->twoColumnDetail('Requests simulated', number_format($stats['requests']));
        $this->components->twoColumnDetail('Queries simulated', number_format($stats['queries']));
        $this->components->twoColumnDetail('Job attempts simulated', number_format($stats['jobs']));
        $this->components->twoColumnDetail('Request traces kept', number_format($stats['kept_requests']));
        $this->components->twoColumnDetail('Job runs kept', number_format($stats['kept_jobs']));
        $this->components->twoColumnDetail('Took', round(microtime(true) - $started, 1).'s');

        return self::SUCCESS;
    }
}
