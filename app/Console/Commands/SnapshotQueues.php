<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('obs:snapshot-queues')]
#[Description('Sample queue depth and oldest pending job age (scheduled every minute)')]
class SnapshotQueues extends Command
{
    public function handle(QueueFactory $queues): int
    {
        $connection = $queues->connection();
        $now = now()->getTimestamp();
        $takenAt = intdiv($now, 60) * 60;

        foreach (config('observability.queues') as $queue) {
            try {
                $oldest = method_exists($connection, 'creationTimeOfOldestPendingJob') ? $connection->creationTimeOfOldestPendingJob($queue) : null;

                DB::table('obs_queue_snapshots')->upsert([[
                    'taken_at' => $takenAt,
                    'queue' => $queue,
                    'pending' => method_exists($connection, 'pendingSize') ? $connection->pendingSize($queue) : $connection->size($queue),
                    'delayed' => method_exists($connection, 'delayedSize') ? $connection->delayedSize($queue) : 0,
                    'reserved' => method_exists($connection, 'reservedSize') ? $connection->reservedSize($queue) : 0,
                    'oldest_pending_s' => $oldest ? max(0, $now - $oldest) : null,
                ]], ['queue', 'taken_at'], ['pending', 'delayed', 'reserved', 'oldest_pending_s']);
            } catch (Throwable $e) {
                $this->components->warn("Could not sample [{$queue}]: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
