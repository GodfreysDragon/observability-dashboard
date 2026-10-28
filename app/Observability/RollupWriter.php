<?php

namespace App\Observability;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Writes rollup increments as a single upsert per batch:
 *
 *   INSERT ... ON CONFLICT (period, bucket, route) DO UPDATE SET count = count + excluded.count, ...
 *
 * Concurrent writers to the same row are serialised by the database's own row lock for
 * the duration of one statement, so there is no read-modify-write window and nothing
 * to retry. MySQL/MariaDB and SQLite/Postgres spell "the incoming value" and "max of
 * two values" differently, which is all the driver switch below handles.
 */
final class RollupWriter
{
    /** SQLite's bound-parameter limit is 32,766; ~70 columns x 200 rows stays well under. */
    private const int CHUNK = 200;

    private const array DIMENSIONS = [
        'obs_request_rollups' => ['route'],
        'obs_query_rollups' => ['fingerprint'],
        'obs_job_rollups' => ['queue', 'job'],
        'obs_exception_rollups' => ['group_id'],
    ];

    public function write(RollupBuffer $buffer, ?Connection $connection = null): void
    {
        $connection ??= DB::connection();

        foreach ($buffer->rows() as $table => $rows) {
            $columns = RollupBuffer::columnsFor($table);
            $update = [];

            foreach ($columns['sums'] as $column) {
                $update[$column] = $this->increment($connection, $table, $column);
            }

            foreach ($columns['max'] as $column) {
                $update[$column] = $this->greatest($connection, $table, $column);
            }

            if ($columns['histogram']) {
                foreach (Histogram::columns() as $column) {
                    $update[$column] = $this->increment($connection, $table, $column);
                }
            }

            $uniqueBy = ['period', 'bucket', ...self::DIMENSIONS[$table]];

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                $connection->table($table)->upsert($chunk, $uniqueBy, $update);
            }
        }
    }

    private function increment(Connection $connection, string $table, string $column): Expression
    {
        return new Expression($this->current($connection, $table, $column).' + '.$this->incoming($connection, $column));
    }

    private function greatest(Connection $connection, string $table, string $column): Expression
    {
        $function = $connection->getDriverName() === 'sqlite' ? 'max' : 'greatest';

        return new Expression("{$function}(".$this->current($connection, $table, $column).', '.$this->incoming($connection, $column).')');
    }

    private function current(Connection $connection, string $table, string $column): string
    {
        $grammar = $connection->getQueryGrammar();

        return $grammar->wrap($table).'.'.$grammar->wrap($column);
    }

    private function incoming(Connection $connection, string $column): string
    {
        $grammar = $connection->getQueryGrammar();

        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->getConfig('use_upsert_alias')
                ? $grammar->wrap('laravel_upsert_alias').'.'.$grammar->wrap($column)
                : 'values('.$grammar->wrap($column).')',
            default => 'excluded.'.$grammar->wrap($column),
        };
    }
}
