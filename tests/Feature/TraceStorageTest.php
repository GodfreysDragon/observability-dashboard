<?php

namespace Tests\Feature;

use App\Observability\SqlNormalizer;
use App\Observability\Trace;
use App\Observability\TraceWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TraceStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['observability.sample_rate' => 0.0]);
    }

    public function test_query_rollups_count_every_query_past_the_stored_query_cap(): void
    {
        $select = SqlNormalizer::normalize('select * from "customers" where "id" = ?');
        $update = SqlNormalizer::normalize('update "invoices" set "sent_at" = ? where "id" = ?');
        $trace = new Trace('request', ['method' => 'GET']);

        for ($i = 0; $i < 550; $i++) {
            $trace->addQuery(SqlNormalizer::fingerprint($select), $select, 1.0, null);
        }

        for ($i = 0; $i < 60; $i++) {
            $trace->addQuery(SqlNormalizer::fingerprint($update), $update, 2.0, null);
        }

        $this->writeRequest($trace);

        $calls = DB::table('obs_query_rollups')->where('period', 60)->pluck('calls', 'fingerprint');
        $this->assertEquals(550, $calls[SqlNormalizer::fingerprint($select)]);
        $this->assertEquals(60, $calls[SqlNormalizer::fingerprint($update)]);
        $this->assertDatabaseHas('obs_query_fingerprints', ['fingerprint' => SqlNormalizer::fingerprint($update)]);
        $this->assertDatabaseCount('obs_queries', Trace::MAX_STORED_QUERIES);
    }

    public function test_a_slow_query_past_the_stored_query_cap_still_keeps_the_trace(): void
    {
        $sql = SqlNormalizer::normalize('insert into "payments" ("amount_cents") values (?)');
        $trace = new Trace('request', ['method' => 'POST']);

        for ($i = 0; $i < Trace::MAX_STORED_QUERIES; $i++) {
            $trace->addQuery(SqlNormalizer::fingerprint($sql), $sql, 1.0, null);
        }

        $trace->addQuery(SqlNormalizer::fingerprint($sql), $sql, 250.0, null);

        $this->writeRequest($trace);

        $this->assertSame('slow_query', DB::table('obs_requests')->value('sample_reason'));
    }

    public function test_trace_owner_and_retention_columns_are_indexed(): void
    {
        foreach (['obs_queries', 'obs_exceptions'] as $table) {
            $this->assertTrue(Schema::hasIndex($table, ['request_id']), "{$table}.request_id");
            $this->assertTrue(Schema::hasIndex($table, ['job_run_id']), "{$table}.job_run_id");
            $this->assertTrue(Schema::hasIndex($table, ['occurred_at']), "{$table}.occurred_at");
        }
    }

    private function writeRequest(Trace $trace): void
    {
        app(TraceWriter::class)->writeRequest($trace, [
            'method' => $trace->meta['method'],
            'route' => $trace->meta['method'].' /ledgerline/invoices',
            'uri' => '/ledgerline/invoices',
            'status' => 200,
            'duration_ms' => 20.0,
            'memory_mb' => 4.0,
            'user_id' => null,
        ]);
    }
}
