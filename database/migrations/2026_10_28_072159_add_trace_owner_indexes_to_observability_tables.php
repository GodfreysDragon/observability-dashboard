<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Only MySQL indexes foreign key columns by itself. On SQLite and Postgres every trace
 * detail lookup, and every cascaded delete during obs:prune, scanned obs_queries in full.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('obs_queries', function (Blueprint $table) {
            $table->index('request_id');
            $table->index('job_run_id');
            $table->index('occurred_at');
        });

        Schema::table('obs_exceptions', function (Blueprint $table) {
            $table->index('request_id');
            $table->index('job_run_id');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::table('obs_queries', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropIndex(['job_run_id']);
            $table->dropIndex(['occurred_at']);
        });

        Schema::table('obs_exceptions', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropIndex(['job_run_id']);
            $table->dropIndex(['occurred_at']);
        });
    }
};
