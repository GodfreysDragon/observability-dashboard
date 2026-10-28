<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_exception_groups_not_seen_within_retention_are_pruned(): void
    {
        $stale = $this->exceptionGroup('stale', now()->subDays(31));
        $recent = $this->exceptionGroup('recent', now()->subDays(29));

        $this->artisan('obs:prune')->assertSuccessful();

        $this->assertDatabaseMissing('obs_exception_groups', ['id' => $stale]);
        $this->assertDatabaseHas('obs_exception_groups', ['id' => $recent]);
    }

    public function test_query_fingerprints_without_retained_rollups_or_queries_are_pruned(): void
    {
        $this->fingerprint('aaaaaaaaaaaaaaaa');
        $this->fingerprint('bbbbbbbbbbbbbbbb');
        DB::table('obs_query_rollups')->insert([
            'period' => 3600,
            'bucket' => intdiv(now()->getTimestamp(), 3600) * 3600,
            'fingerprint' => 'bbbbbbbbbbbbbbbb',
            'calls' => 1,
        ]);

        $this->artisan('obs:prune')->assertSuccessful();

        $this->assertDatabaseMissing('obs_query_fingerprints', ['fingerprint' => 'aaaaaaaaaaaaaaaa']);
        $this->assertDatabaseHas('obs_query_fingerprints', ['fingerprint' => 'bbbbbbbbbbbbbbbb']);
    }

    private function exceptionGroup(string $fingerprint, \DateTimeInterface $lastSeen): int
    {
        return DB::table('obs_exception_groups')->insertGetId([
            'fingerprint' => sha1($fingerprint),
            'class' => 'RuntimeException',
            'message' => 'Boom',
            'file' => 'app/Jobs/SendInvoiceEmail.php',
            'line' => 33,
            'first_seen_at' => $lastSeen->format('Y-m-d H:i:s'),
            'last_seen_at' => $lastSeen->format('Y-m-d H:i:s'),
            'first_release' => 'dev',
        ]);
    }

    private function fingerprint(string $fingerprint): void
    {
        DB::table('obs_query_fingerprints')->insert([
            'fingerprint' => $fingerprint,
            'sql' => 'select ?',
            'connection' => 'sqlite',
            'first_seen_at' => now()->subDays(40),
        ]);
    }
}
