<?php

namespace Tests\Feature;

use App\Observability\Reports\SloReport;
use App\Observability\RollupBuffer;
use App\Observability\RollupWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SloReportTest extends TestCase
{
    use RefreshDatabase;

    private const int NOW = 1_790_000_000;

    public function test_burn_rate_thresholds_scale_with_the_slo_window(): void
    {
        $this->assertSame(['fast' => 14.4, 'slow' => 6.0], SloReport::burnThresholds(30));
        $this->assertSame(['fast' => 3.36, 'slow' => 1.4], SloReport::burnThresholds(7));
    }

    public function test_a_4x_burn_pages_against_a_7_day_window(): void
    {
        config(['observability.slo.window_days' => 7]);

        $this->recordRequests(self::NOW - 3 * 86400, count: 100_000, errors: 0);
        $this->recordRequests(self::NOW - 600, count: 1000, errors: 20);

        $availability = SloReport::build(self::NOW)['objectives'][0];

        $this->assertSame('availability', $availability['key']);
        $this->assertEquals(4.0, $availability['burn_rate_1h']);
        $this->assertGreaterThan(0, $availability['budget_remaining']);
        $this->assertSame('critical', $availability['status']);
    }

    public function test_the_slo_window_follows_the_configured_days(): void
    {
        config(['observability.slo.window_days' => 7]);
        $this->recordRequests(self::NOW - 10 * 86400, count: 100, errors: 100);

        $this->assertNull(SloReport::build(self::NOW)['objectives'][0]['attained']);

        config(['observability.slo.window_days' => 14]);

        $this->assertEquals(0.0, SloReport::build(self::NOW)['objectives'][0]['attained']);
    }

    private function recordRequests(int $timestamp, int $count, int $errors): void
    {
        $buffer = new RollupBuffer;
        $buffer->addRequest($timestamp, 'GET /ledgerline/invoices', 200, 50.0, 2, 1.0, false, $count - $errors);

        if ($errors > 0) {
            $buffer->addRequest($timestamp, 'GET /ledgerline/invoices', 500, 50.0, 2, 1.0, false, $errors);
        }

        (new RollupWriter)->write($buffer);
    }
}
