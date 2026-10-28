<?php

namespace Tests\Feature;

use App\Observability\Simulation\LedgerlineSimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('slow')]
class SimulatorTest extends TestCase
{
    use RefreshDatabase;

    private const int NOW = 1_790_000_000;

    public function test_the_same_seed_and_anchor_time_produce_identical_telemetry(): void
    {
        $first = $this->simulate(seed: 7);
        $second = $this->simulate(seed: 7);

        $this->assertGreaterThan(0, $first['stats']['requests']);
        $this->assertSame($first, $second);
    }

    public function test_a_different_seed_produces_different_traffic(): void
    {
        $this->assertNotSame($this->simulate(seed: 7)['stats'], $this->simulate(seed: 8)['stats']);
    }

    /**
     * @return array{stats: array<string, int>, rollups: array<string, mixed>}
     */
    private function simulate(int $seed): array
    {
        $stats = app(LedgerlineSimulator::class)->run(days: 1, seed: $seed, now: self::NOW);

        return [
            'stats' => $stats,
            'rollups' => (array) DB::table('obs_request_rollups')
                ->selectRaw('COUNT(*) AS rows_written, SUM(count) AS requests, SUM(server_errors) AS errors, SUM(h20) AS h20, SUM(n_plus_one) AS n_plus_one')
                ->first(),
        ];
    }
}
