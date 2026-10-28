<?php

namespace Tests\Feature;

use App\Jobs\GenerateInvoicePdf;
use App\Models\Ledgerline\Customer;
use App\Models\Ledgerline\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JobInstrumentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['observability.sample_rate' => 0.0, 'queue.default' => 'database']);
    }

    public function test_a_processed_job_records_runtime_and_queue_wait(): void
    {
        GenerateInvoicePdf::dispatch(Invoice::factory()->withLines(2)->create());

        $this->work();

        $rollup = $this->jobRollup();
        $this->assertSame('pdf', $rollup->queue);
        $this->assertEquals(1, $rollup->processed);
        $this->assertEquals(0, $rollup->failed);
        $this->assertEquals(1, $rollup->waited);
        $this->assertGreaterThanOrEqual(0, $rollup->wait_sum);
        $this->assertGreaterThan(0, $rollup->runtime_sum);
        $this->assertDatabaseCount('obs_job_runs', 0);
    }

    public function test_a_retried_then_failed_job_records_each_attempt_and_its_exception_once(): void
    {
        $customer = Customer::factory()->legacyRegion()->create();
        GenerateInvoicePdf::dispatch(Invoice::factory()->withLines(1)->create(['customer_id' => $customer->id]));

        $this->work();
        $this->work();

        $rollup = $this->jobRollup();
        $this->assertEquals(0, $rollup->processed);
        $this->assertEquals(1, $rollup->released);
        $this->assertEquals(1, $rollup->failed);
        $this->assertEquals(1, $rollup->waited, 'Queue wait is measured on the first attempt only.');

        $runs = DB::table('obs_job_runs')->orderBy('attempt')->get();
        $this->assertSame(['released', 'failed'], $runs->pluck('status')->all());
        $this->assertEquals([1, 2], $runs->pluck('attempt')->all());
        $this->assertSame('error', $runs[0]->sample_reason);

        $this->assertSame(2, DB::table('obs_exceptions')->whereNotNull('job_run_id')->count());
        $this->assertDatabaseCount('obs_exceptions', 2);
        $this->assertEquals(2, DB::table('obs_exception_groups')->value('occurrences'));
    }

    public function test_jobs_run_by_the_sync_driver_stay_inside_their_request(): void
    {
        config(['queue.default' => 'sync', 'ledgerline.smtp_failure_percent' => 0]);
        $invoice = Invoice::factory()->withLines(2)->create();

        $this->postJson("/ledgerline/invoices/{$invoice->number}/send")->assertAccepted();

        $this->assertDatabaseCount('obs_job_rollups', 0);
        $this->assertDatabaseCount('obs_job_runs', 0);

        $request = DB::table('obs_request_rollups')->where('period', 60)->sole();
        $this->assertSame('POST /ledgerline/invoices/{invoice}/send', $request->route);
        $this->assertGreaterThan(1, $request->query_count_sum, "The jobs' queries count towards the request.");
    }

    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'pdf', '--once' => true])->assertSuccessful();
    }

    private function jobRollup(): object
    {
        return DB::table('obs_job_rollups')->where('period', 60)->where('job', GenerateInvoicePdf::class)->sole();
    }
}
