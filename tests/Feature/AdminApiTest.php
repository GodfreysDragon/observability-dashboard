<?php

namespace Tests\Feature;

use App\Models\Ledgerline\Customer;
use App\Models\Ledgerline\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function protectedEndpoints(): array
    {
        return [
            'overview' => ['GET', '/api/admin/overview'],
            'routes' => ['GET', '/api/admin/routes'],
            'route detail' => ['GET', '/api/admin/routes/detail?route=GET%20/ledgerline/invoices'],
            'traces' => ['GET', '/api/admin/traces'],
            'request trace' => ['GET', '/api/admin/traces/requests/1'],
            'job trace' => ['GET', '/api/admin/traces/jobs/1'],
            'queries' => ['GET', '/api/admin/queries'],
            'query detail' => ['GET', '/api/admin/queries/0123456789abcdef'],
            'exceptions' => ['GET', '/api/admin/exceptions'],
            'exception detail' => ['GET', '/api/admin/exceptions/1'],
            'exception resolution' => ['PUT', '/api/admin/exceptions/1/resolution'],
            'queues' => ['GET', '/api/admin/queues'],
            'deploys' => ['GET', '/api/admin/deploys'],
        ];
    }

    #[DataProvider('protectedEndpoints')]
    public function test_guests_are_rejected(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidQueries(): array
    {
        return [
            'unknown range' => ['/api/admin/overview?range=2d'],
            'unknown query sort' => ['/api/admin/queries?sort=sql'],
            'unknown status class' => ['/api/admin/traces?status=6xx'],
            'unknown sample reason' => ['/api/admin/traces?reason=boredom'],
            'negative duration filter' => ['/api/admin/traces?min_ms=-1'],
            'missing route' => ['/api/admin/routes/detail'],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_filters_are_rejected(string $uri): void
    {
        $this->actingAs(User::factory()->create())->getJson($uri)->assertUnprocessable();
    }

    public function test_list_endpoints_return_their_report_shapes(): void
    {
        $this->generateTraffic();
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/admin/overview?range=1h')->assertOk()->assertJsonStructure([
            'range' => ['key', 'from', 'to', 'step', 'now'],
            'current' => ['count', 'rpm', 'error_rate', 'apdex', 'p50', 'p95', 'p99'],
            'previous', 'series' => [['t', 'count', 'p95']], 'deploys', 'routes', 'queues', 'exceptions',
            'slo' => ['window_days', 'objectives' => [['key', 'status', 'burn_rate_1h', 'fast_burn_threshold']]],
        ]);
        $this->getJson('/api/admin/routes')->assertOk()->assertJsonStructure(['data' => [['route', 'count', 'p95', 'time_share', 'p95_change']]]);
        $this->getJson('/api/admin/routes/detail?route='.urlencode('GET /ledgerline/invoices'))->assertOk()
            ->assertJsonStructure(['route', 'current', 'previous', 'series', 'distribution' => [['from', 'to', 'count']], 'deploys', 'slowest', 'queries']);
        $this->getJson('/api/admin/traces')->assertOk()->assertJsonStructure(['data' => [['id', 'route', 'status', 'duration_ms', 'sample_reason']], 'next_cursor']);
        $this->getJson('/api/admin/queries')->assertOk()->assertJsonStructure(['data' => [['fingerprint', 'sql', 'calls', 'total_ms', 'p95', 'n_plus_one']]]);
        $this->getJson('/api/admin/exceptions')->assertOk()->assertJsonStructure(['data' => [['id', 'class', 'count', 'trend', 'is_new']]]);
        $this->getJson('/api/admin/queues')->assertOk()->assertJsonStructure(['queues', 'series', 'jobs', 'failures']);
        $this->getJson('/api/admin/deploys')->assertOk()->assertJsonStructure(['data']);
    }

    public function test_detail_endpoints_follow_ids_from_the_lists(): void
    {
        $this->generateTraffic();
        $this->actingAs(User::factory()->create());

        $trace = $this->getJson('/api/admin/traces?reason=n_plus_one')->json('data.0');
        $this->getJson("/api/admin/traces/requests/{$trace['id']}")->assertOk()
            ->assertJsonStructure(['kind', 'trace', 'queries' => [['fingerprint', 'sql', 'offset_ms', 'repeats']], 'n_plus_one' => [['fingerprint', 'count', 'location']], 'exceptions'])
            ->assertJsonPath('kind', 'request');

        $fingerprint = $this->getJson('/api/admin/queries')->json('data.0.fingerprint');
        $this->getJson("/api/admin/queries/{$fingerprint}")->assertOk()
            ->assertJsonStructure(['fingerprint', 'sql', 'calls', 'p95', 'series', 'callers', 'slowest'])
            ->assertJsonPath('fingerprint', $fingerprint);

        $group = $this->getJson('/api/admin/exceptions')->json('data.0.id');
        $this->getJson("/api/admin/exceptions/{$group}")->assertOk()->assertJsonPath('group.id', $group);
    }

    public function test_missing_records_are_not_found(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/admin/traces/requests/999')->assertNotFound();
        $this->getJson('/api/admin/traces/jobs/999')->assertNotFound();
        $this->getJson('/api/admin/queries/0123456789abcdef')->assertNotFound();
        $this->getJson('/api/admin/exceptions/999')->assertNotFound();
        $this->putJson('/api/admin/exceptions/999/resolution', ['resolved' => true])->assertNotFound();
    }

    public function test_an_exception_group_can_be_resolved_and_reopened(): void
    {
        $this->generateTraffic();
        $this->actingAs(User::factory()->create());
        $group = DB::table('obs_exception_groups')->value('id');

        $this->putJson("/api/admin/exceptions/{$group}/resolution", [])->assertUnprocessable();

        $this->putJson("/api/admin/exceptions/{$group}/resolution", ['resolved' => true])->assertOk();
        $this->assertNotNull(DB::table('obs_exception_groups')->value('resolved_at'));
        $this->assertSame([], $this->getJson('/api/admin/overview')->json('exceptions'));

        $this->putJson("/api/admin/exceptions/{$group}/resolution", ['resolved' => false])->assertOk()->assertJsonPath('resolved_at', null);
    }

    public function test_signing_in_starts_a_session_and_a_wrong_password_does_not(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();

        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonStructure(['csrf_token']);
        $this->assertAuthenticatedAs($user);
    }

    /**
     * A healthy request, an N+1 on the invoice list and a server error, so every report
     * has a row and a kept trace to point at.
     */
    private function generateTraffic(): void
    {
        config(['observability.sample_rate' => 0.0, 'ledgerline.eager_load' => false]);

        Invoice::factory()->count(6)->withLines(1)->create();
        $legacy = Invoice::factory()->withLines(1)->create(['customer_id' => Customer::factory()->legacyRegion()->create()->id]);

        $this->getJson('/ledgerline/invoices')->assertOk();
        $this->getJson("/ledgerline/invoices/{$legacy->number}/pdf")->assertServerError();
    }
}
