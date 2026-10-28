<?php

namespace Tests\Feature;

use App\Observability\Reports\DeployReport;
use App\Observability\RollupBuffer;
use App\Observability\RollupWriter;
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

    public function test_a_route_whose_p95_rises_after_a_deploy_regresses_it(): void
    {
        $at = self::NOW - 3 * 3600;
        $this->deploy('v2', $at);
        $this->traffic($at - 3 * 3600, $at, durationMs: 100.0);
        $this->traffic($at, self::NOW, durationMs: 400.0);

        $deploy = DeployReport::list(now: self::NOW)[0];

        $this->assertSame('regressed', $deploy['verdict']);
        $this->assertSame('GET /ledgerline/invoices', $deploy['routes'][0]['route']);
        $this->assertGreaterThan(2.0, $deploy['routes'][0]['p95_change']);
    }

    public function test_a_route_whose_p95_falls_after_a_deploy_improves_it(): void
    {
        $at = self::NOW - 3 * 3600;
        $this->deploy('v2', $at);
        $this->traffic($at - 3 * 3600, $at, durationMs: 400.0);
        $this->traffic($at, self::NOW, durationMs: 100.0);

        $this->assertSame('improved', DeployReport::list(now: self::NOW)[0]['verdict']);
    }

    public function test_hour_windows_snap_inwards_so_the_deploy_hour_counts_on_neither_side(): void
    {
        // Older than the minute-rollup retention, so the comparison reads hour rollups.
        $edge = intdiv(self::NOW - 60 * 3600, 3600) * 3600;
        $this->deploy('v2', $edge + 1800);
        $this->traffic($edge - 6 * 3600, $edge, durationMs: 100.0, periods: [3600]);
        $this->traffic($edge, $edge + 3600, durationMs: 2000.0, periods: [3600]);
        $this->traffic($edge + 3600, $edge + 7 * 3600, durationMs: 100.0, periods: [3600]);

        $deploy = DeployReport::list(now: self::NOW)[0];

        $this->assertSame('neutral', $deploy['verdict']);
        $this->assertSame(600, $deploy['before']['count']);
        $this->assertSame(600, $deploy['after']['count']);
    }

    /**
     * 100 requests at every step from `$from` up to (not including) `$to`.
     *
     * @param  list<int>  $periods
     */
    private function traffic(int $from, int $to, float $durationMs, array $periods = RollupBuffer::PERIODS): void
    {
        $buffer = new RollupBuffer(periods: $periods);
        $step = min($periods);

        for ($t = $from; $t < $to; $t += $step) {
            $buffer->addRequest($t, 'GET /ledgerline/invoices', 200, $durationMs, 4, 2.0, false, 100);
        }

        (new RollupWriter)->write($buffer);
    }

    private function deploy(string $release, int $at): void
    {
        DB::table('obs_deploys')->insert(['release' => $release, 'deployed_at' => gmdate('Y-m-d H:i:s', $at)]);
    }
}
