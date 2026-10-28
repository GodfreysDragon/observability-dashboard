<?php

namespace Tests\Feature;

use App\Observability\Reports\DeployReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeployReportTest extends TestCase
{
    use RefreshDatabase;

    private const int NOW = 1_790_000_000;

    public function test_only_the_latest_deploys_are_listed_with_windows_bounded_by_unlisted_neighbours(): void
    {
        // v2 lands 30 minutes after v1; every later deploy is an hour after the one before.
        $this->deploy('v1', self::NOW - 14 * 3600 - 1800);

        for ($i = 2; $i <= 15; $i++) {
            $this->deploy("v{$i}", self::NOW - (16 - $i) * 3600);
        }

        $list = DeployReport::list(limit: 12, now: self::NOW);

        $this->assertSame(['v15', 'v14', 'v13', 'v12', 'v11', 'v10', 'v9', 'v8', 'v7', 'v6', 'v5', 'v4'], array_column($list, 'release'));
        $this->assertSame(3600, end($list)['window_s']);

        $withOldest = DeployReport::list(limit: 14, now: self::NOW);

        $this->assertSame('v2', end($withOldest)['release']);
        $this->assertSame(1800, end($withOldest)['window_s']);
    }

    public function test_a_short_window_is_pending_when_recent_and_insufficient_when_squeezed_by_a_neighbour(): void
    {
        $this->deploy('v1', self::NOW - 7200);
        $this->deploy('v2', self::NOW - 7200 + 300);
        $this->deploy('v3', self::NOW - 300);

        $verdicts = array_column(DeployReport::list(now: self::NOW), 'verdict', 'release');

        $this->assertSame('insufficient', $verdicts['v1']);
        $this->assertSame('insufficient', $verdicts['v2']);
        $this->assertSame('pending', $verdicts['v3']);
    }

    private function deploy(string $release, int $at): void
    {
        DB::table('obs_deploys')->insert(['release' => $release, 'deployed_at' => gmdate('Y-m-d H:i:s', $at)]);
    }
}
