<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function test_rollups_are_kept_for_their_resolutions_retention(): void
    {
        $now = now()->getTimestamp();
        $minuteOld = $this->rollup(60, $now - 51 * 3600);
        $minuteRecent = $this->rollup(60, $now - 49 * 3600);
        $hourOld = $this->rollup(3600, $now - 31 * 86400);
        $hourRecent = $this->rollup(3600, $now - 29 * 86400);

        $this->artisan('obs:prune')->assertSuccessful();

        $this->assertDatabaseMissing('obs_request_rollups', ['id' => $minuteOld]);
        $this->assertDatabaseHas('obs_request_rollups', ['id' => $minuteRecent]);
        $this->assertDatabaseMissing('obs_request_rollups', ['id' => $hourOld]);
        $this->assertDatabaseHas('obs_request_rollups', ['id' => $hourRecent]);
    }

    public function test_traces_and_their_queries_expire_together(): void
    {
        $old = $this->trace(now()->subHours(73));
        $recent = $this->trace(now()->subHours(71));

        $this->artisan('obs:prune')->assertSuccessful();

        $this->assertDatabaseMissing('obs_requests', ['id' => $old]);
        $this->assertDatabaseMissing('obs_queries', ['request_id' => $old]);
        $this->assertDatabaseHas('obs_requests', ['id' => $recent]);
        $this->assertDatabaseHas('obs_queries', ['request_id' => $recent]);
    }

    public function test_queue_snapshots_expire(): void
    {
        foreach (['old' => 9, 'recent' => 7] as $queue => $days) {
            DB::table('obs_queue_snapshots')->insert(['taken_at' => now()->subDays($days)->getTimestamp(), 'queue' => $queue, 'pending' => 1]);
        }

        $this->artisan('obs:prune')->assertSuccessful();

        $this->assertSame(['recent'], DB::table('obs_queue_snapshots')->pluck('queue')->all());
    }

    private function rollup(int $period, int $timestamp): int
    {
        return DB::table('obs_request_rollups')->insertGetId([
            'period' => $period,
            'bucket' => $timestamp - $timestamp % $period,
            'route' => 'GET /ledgerline/invoices',
        ]);
    }

    private function trace(\DateTimeInterface $at): int
    {
        $id = DB::table('obs_requests')->insertGetId([
            'trace_id' => (string) Str::ulid(),
            'occurred_at' => $at->format('Y-m-d H:i:s'),
            'method' => 'GET',
            'route' => 'GET /ledgerline/invoices',
            'uri' => '/ledgerline/invoices',
            'status' => 200,
            'duration_ms' => 20,
            'memory_mb' => 4,
            'query_count' => 1,
            'query_ms' => 1,
            'sample_reason' => 'random',
            'release' => 'dev',
        ]);

        DB::table('obs_queries')->insert([
            'request_id' => $id,
            'occurred_at' => $at->format('Y-m-d H:i:s'),
            'fingerprint' => 'aaaaaaaaaaaaaaaa',
            'sql' => 'select ?',
            'duration_ms' => 1,
            'offset_ms' => 0,
        ]);

        return $id;
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
