<?php

namespace Tests\Feature;

use App\Models\Ledgerline\Customer;
use App\Models\Ledgerline\Invoice;
use App\Observability\Histogram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RequestInstrumentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['observability.sample_rate' => 0.0]);
    }

    public function test_every_request_feeds_minute_and_hour_rollups_keyed_by_route_pattern(): void
    {
        $invoices = Invoice::factory()->count(2)->withLines(2)->create();

        $this->getJson('/ledgerline/invoices/'.$invoices[0]->number)->assertOk();
        $this->getJson('/ledgerline/invoices/'.$invoices[1]->number)->assertOk();

        $rows = DB::table('obs_request_rollups')->orderBy('period')->get();

        $this->assertSame([60, 3600], $rows->pluck('period')->map(fn ($p) => (int) $p)->all());

        foreach ($rows as $row) {
            $this->assertSame('GET /ledgerline/invoices/{invoice}', $row->route);
            $this->assertEquals(2, $row->count);
            $this->assertEquals(0, $row->server_errors);
            $this->assertEquals(2, Histogram::fromRow($row)->count());
            $this->assertGreaterThan(0, $row->query_count_sum);
        }
    }

    public function test_unremarkable_requests_are_not_kept_as_traces_when_the_sample_rate_is_zero(): void
    {
        $invoice = Invoice::factory()->withLines(1)->create();

        $this->getJson('/ledgerline/invoices/'.$invoice->number)->assertOk();

        $this->assertDatabaseCount('obs_requests', 0);
        $this->assertDatabaseCount('obs_queries', 0);
        $this->assertGreaterThan(0, DB::table('obs_query_rollups')->where('period', 60)->sum('calls'));
    }

    public function test_an_n_plus_one_on_the_invoice_list_is_flagged_and_its_trace_kept(): void
    {
        config(['ledgerline.eager_load' => false]);
        Invoice::factory()->count(8)->withLines(1)->create();

        $this->getJson('/ledgerline/invoices')->assertOk();

        $trace = DB::table('obs_requests')->sole();
        $this->assertSame('n_plus_one', $trace->sample_reason);
        $this->assertEquals(1, $trace->n_plus_one);
        $this->assertGreaterThanOrEqual(16, $trace->query_count);

        $lazyLoad = DB::table('obs_queries')
            ->where('request_id', $trace->id)
            ->where('sql', 'like', '%from "customers" where "customers"."id" = ?%')
            ->get();

        $this->assertCount(8, $lazyLoad);
        $this->assertCount(1, $lazyLoad->pluck('fingerprint')->unique());
        $this->assertStringStartsWith('app/Http/Controllers/Ledgerline/InvoiceController.php:', $lazyLoad[0]->location);

        $this->assertEquals(1, DB::table('obs_query_rollups')
            ->where('period', 60)
            ->where('fingerprint', $lazyLoad[0]->fingerprint)
            ->value('n_plus_one'));
    }

    public function test_eager_loading_keeps_the_same_page_under_the_n_plus_one_threshold(): void
    {
        Invoice::factory()->count(8)->withLines(1)->create();

        $this->getJson('/ledgerline/invoices')->assertOk();

        $this->assertDatabaseCount('obs_requests', 0);
        $this->assertEquals(0, DB::table('obs_request_rollups')->sum('n_plus_one'));
    }

    public function test_an_exception_is_grouped_counted_and_linked_to_its_kept_trace(): void
    {
        $customer = Customer::factory()->legacyRegion()->create();
        $invoices = Invoice::factory()->count(3)->withLines(1)->create(['customer_id' => $customer->id]);

        foreach ($invoices as $invoice) {
            $this->getJson("/ledgerline/invoices/{$invoice->number}/pdf")->assertServerError();
        }

        $group = DB::table('obs_exception_groups')->sole();
        $this->assertSame('ErrorException', $group->class);
        $this->assertSame('app/Ledgerline/InvoiceRenderer.php', $group->file);
        $this->assertEquals(3, $group->occurrences);
        $this->assertStringContainsString('Undefined array key "XI"', $group->message);

        $this->assertSame(3, DB::table('obs_requests')->where('sample_reason', 'error')->count());
        $this->assertSame(3, DB::table('obs_exceptions')->whereNotNull('request_id')->count());
        $this->assertEquals(3, DB::table('obs_exception_rollups')->where('period', 60)->value('count'));
        $this->assertEquals(3, DB::table('obs_request_rollups')->where('period', 60)->value('server_errors'));
    }

    public function test_a_resolved_exception_group_reopens_when_it_happens_again(): void
    {
        $customer = Customer::factory()->legacyRegion()->create();
        $invoice = Invoice::factory()->withLines(1)->create(['customer_id' => $customer->id]);

        $this->getJson("/ledgerline/invoices/{$invoice->number}/pdf");
        DB::table('obs_exception_groups')->update(['resolved_at' => now()]);
        $this->getJson("/ledgerline/invoices/{$invoice->number}/pdf");

        $this->assertNull(DB::table('obs_exception_groups')->value('resolved_at'));
    }

    public function test_unmatched_urls_share_one_series_to_bound_cardinality(): void
    {
        $this->getJson('/ledgerline/nope/1')->assertNotFound();
        $this->getJson('/ledgerline/nope/2')->assertNotFound();

        $row = DB::table('obs_request_rollups')->where('period', 60)->sole();
        $this->assertSame('GET [unmatched]', $row->route);
        $this->assertEquals(2, $row->client_errors);
    }

    public function test_the_dashboard_and_its_api_are_not_recorded(): void
    {
        $this->get('/');
        $this->getJson('/api/admin/me');

        $this->assertDatabaseCount('obs_request_rollups', 0);
    }

    public function test_a_failing_telemetry_write_never_breaks_the_monitored_request(): void
    {
        $invoice = Invoice::factory()->withLines(1)->create();
        DB::statement('DROP TABLE obs_request_rollups');

        $this->getJson('/ledgerline/invoices/'.$invoice->number)->assertOk();
    }
}
