<?php

namespace Tests\Unit;

use App\Observability\Histogram;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HistogramTest extends TestCase
{
    public function test_bucket_boundaries_are_inclusive_upper_bounds(): void
    {
        $this->assertSame(0, Histogram::bucketFor(0.2));
        $this->assertSame(0, Histogram::bucketFor(1.0));
        $this->assertSame(1, Histogram::bucketFor(1.25));
        $this->assertSame(2, Histogram::bucketFor(1.26));
        $this->assertSame(Histogram::BUCKETS - 1, Histogram::bucketFor(10_000_000));
    }

    /**
     * @return array<string, array{float, float}>
     */
    public static function uniformQuantiles(): array
    {
        return [
            'p50' => [0.50, 500.0],
            'p95' => [0.95, 950.0],
            'p99' => [0.99, 990.0],
        ];
    }

    #[DataProvider('uniformQuantiles')]
    public function test_percentiles_of_a_uniform_distribution_are_within_a_few_percent(float $q, float $expected): void
    {
        $histogram = $this->uniform(1, 1000);

        $this->assertEqualsWithDelta($expected, $histogram->percentile($q, 1000.0), $expected * 0.05);
    }

    public function test_a_percentile_never_exceeds_the_observed_maximum(): void
    {
        $histogram = new Histogram;
        $histogram->record(900.0, 100);

        $this->assertSame(900.0, $histogram->percentile(0.99, 900.0));
    }

    public function test_the_overflow_bucket_reports_the_observed_maximum(): void
    {
        $histogram = new Histogram;
        $histogram->record(120_000.0);

        $this->assertSame(120_000.0, $histogram->percentile(0.5, 120_000.0));
    }

    public function test_an_empty_histogram_has_no_percentile_or_fraction(): void
    {
        $this->assertNull((new Histogram)->percentile(0.5));
        $this->assertNull((new Histogram)->fractionBelow(100.0));
    }

    public function test_fraction_below_matches_a_uniform_distribution(): void
    {
        $histogram = $this->uniform(1, 1000);

        $this->assertEqualsWithDelta(0.25, $histogram->fractionBelow(250.0), 0.01);
        $this->assertEqualsWithDelta(0.97, $histogram->fractionBelow(970.0), 0.01);
        $this->assertSame(1.0, $histogram->fractionBelow(100_000.0));
    }

    public function test_merged_histograms_round_trip_through_columns(): void
    {
        $a = $this->uniform(1, 500);
        $b = $this->uniform(501, 1000);
        $a->merge($b);

        $restored = Histogram::fromRow($a->toColumns());

        $this->assertSame(1000, $restored->count());
        $this->assertSame($this->uniform(1, 1000)->counts(), $restored->counts());
    }

    private function uniform(int $from, int $to): Histogram
    {
        $histogram = new Histogram;

        for ($ms = $from; $ms <= $to; $ms++) {
            $histogram->record((float) $ms);
        }

        return $histogram;
    }
}
