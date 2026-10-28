<?php

use App\Observability\Histogram;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rollups are keyed by (period, bucket, dimension). `period` is 60 or 3600 seconds and
     * `bucket` is the unix timestamp the period starts at, so a coarser step is just
     * `bucket - bucket % step` in SQL. Histogram buckets are plain integer columns so the
     * write path is an atomic upsert-increment and reads can SUM them.
     */
    public function up(): void
    {
        Schema::create('obs_request_rollups', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('period');
            $table->unsignedInteger('bucket');
            $table->string('route', 191);
            $table->unsignedInteger('count')->default(0);
            $table->unsignedInteger('server_errors')->default(0);
            $table->unsignedInteger('client_errors')->default(0);
            $table->unsignedInteger('satisfied')->default(0);
            $table->unsignedInteger('tolerating')->default(0);
            $table->double('duration_sum')->default(0);
            $table->double('duration_max')->default(0);
            $table->unsignedInteger('query_count_sum')->default(0);
            $table->double('query_ms_sum')->default(0);
            $table->unsignedInteger('n_plus_one')->default(0);
            $this->histogramColumns($table);

            $table->unique(['period', 'bucket', 'route']);
        });

        Schema::create('obs_query_rollups', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('period');
            $table->unsignedInteger('bucket');
            $table->char('fingerprint', 16);
            $table->unsignedInteger('calls')->default(0);
            $table->double('total_ms')->default(0);
            $table->double('max_ms')->default(0);
            $table->unsignedInteger('n_plus_one')->default(0);
            $this->histogramColumns($table);

            $table->unique(['period', 'bucket', 'fingerprint']);
        });

        Schema::create('obs_job_rollups', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('period');
            $table->unsignedInteger('bucket');
            $table->string('queue', 64);
            $table->string('job', 191);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('released')->default(0);
            $table->double('runtime_sum')->default(0);
            $table->double('runtime_max')->default(0);
            $table->unsignedInteger('waited')->default(0);
            $table->double('wait_sum')->default(0);
            $table->double('wait_max')->default(0);
            $this->histogramColumns($table);

            $table->unique(['period', 'bucket', 'queue', 'job']);
        });

        Schema::create('obs_exception_rollups', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('period');
            $table->unsignedInteger('bucket');
            $table->unsignedBigInteger('group_id');
            $table->unsignedInteger('count')->default(0);

            $table->unique(['period', 'bucket', 'group_id']);
        });

        Schema::create('obs_query_fingerprints', function (Blueprint $table) {
            $table->char('fingerprint', 16)->primary();
            $table->text('sql');
            $table->string('connection', 64);
            $table->timestamp('first_seen_at');
        });

        Schema::create('obs_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('trace_id')->unique();
            $table->dateTime('occurred_at', 3)->index();
            $table->string('method', 10);
            $table->string('route', 191);
            $table->string('uri', 500);
            $table->unsignedSmallInteger('status');
            $table->double('duration_ms');
            $table->double('memory_mb');
            $table->unsignedInteger('query_count');
            $table->double('query_ms');
            $table->boolean('n_plus_one')->default(false);
            $table->string('sample_reason', 16);
            $table->string('release', 64);
            $table->unsignedBigInteger('user_id')->nullable();

            $table->index(['route', 'occurred_at']);
            $table->index(['status', 'occurred_at']);
        });

        Schema::create('obs_job_runs', function (Blueprint $table) {
            $table->id();
            $table->string('job_uuid', 64)->nullable()->index();
            $table->dateTime('occurred_at', 3)->index();
            $table->string('connection', 64);
            $table->string('queue', 64);
            $table->string('job', 191);
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempt');
            $table->double('wait_ms')->nullable();
            $table->double('runtime_ms');
            $table->unsignedInteger('query_count');
            $table->string('sample_reason', 16);
            $table->string('release', 64);

            $table->index(['queue', 'status', 'occurred_at']);
        });

        Schema::create('obs_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->nullable()->constrained('obs_requests')->cascadeOnDelete();
            $table->foreignId('job_run_id')->nullable()->constrained('obs_job_runs')->cascadeOnDelete();
            $table->dateTime('occurred_at', 3);
            $table->char('fingerprint', 16);
            $table->text('sql');
            $table->double('duration_ms');
            $table->double('offset_ms');
            $table->string('location', 255)->nullable();

            $table->index(['fingerprint', 'occurred_at']);
            $table->index(['duration_ms']);
        });

        Schema::create('obs_exception_groups', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 40)->unique();
            $table->string('class', 191);
            $table->text('message');
            $table->string('file', 255);
            $table->unsignedInteger('line');
            $table->dateTime('first_seen_at', 3);
            $table->dateTime('last_seen_at', 3)->index();
            $table->string('first_release', 64);
            $table->unsignedBigInteger('occurrences')->default(0);
            $table->timestamp('resolved_at')->nullable();
        });

        Schema::create('obs_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('obs_exception_groups')->cascadeOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('obs_requests')->nullOnDelete();
            $table->foreignId('job_run_id')->nullable()->constrained('obs_job_runs')->nullOnDelete();
            $table->dateTime('occurred_at', 3);
            $table->text('message');
            $table->json('trace');
            $table->string('release', 64);

            $table->index(['group_id', 'occurred_at']);
        });

        Schema::create('obs_queue_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('taken_at');
            $table->string('queue', 64);
            $table->unsignedInteger('pending');
            $table->unsignedInteger('delayed')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->unsignedInteger('oldest_pending_s')->nullable();

            $table->unique(['queue', 'taken_at']);
            $table->index('taken_at');
        });

        Schema::create('obs_deploys', function (Blueprint $table) {
            $table->id();
            $table->string('release', 64)->unique();
            $table->string('commit', 40)->nullable();
            $table->string('deployed_by', 100)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('deployed_at')->index();
        });
    }

    public function down(): void
    {
        foreach ([
            'obs_deploys', 'obs_queue_snapshots', 'obs_exceptions', 'obs_exception_groups', 'obs_queries',
            'obs_job_runs', 'obs_requests', 'obs_query_fingerprints', 'obs_exception_rollups',
            'obs_job_rollups', 'obs_query_rollups', 'obs_request_rollups',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function histogramColumns(Blueprint $table): void
    {
        foreach (Histogram::columns() as $column) {
            $table->unsignedInteger($column)->default(0);
        }
    }
};
