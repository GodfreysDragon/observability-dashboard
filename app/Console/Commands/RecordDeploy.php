<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('obs:deploy {release : The release tag, matching APP_RELEASE} {--commit=} {--by=} {--notes=}')]
#[Description('Record a deploy marker (run from CI once a release is live)')]
class RecordDeploy extends Command
{
    public function handle(): int
    {
        $release = (string) $this->argument('release');

        DB::table('obs_deploys')->updateOrInsert(['release' => $release], [
            'commit' => $this->option('commit') ? substr((string) $this->option('commit'), 0, 40) : null,
            'deployed_by' => $this->option('by'),
            'notes' => $this->option('notes'),
            'deployed_at' => now()->utc()->format('Y-m-d H:i:s'),
        ]);

        $this->components->info("Recorded deploy {$release}.");

        return self::SUCCESS;
    }
}
