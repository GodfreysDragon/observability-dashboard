<?php

namespace Tests\Feature;

use App\Models\Ledgerline\Customer;
use App\Models\Ledgerline\Invoice;
use App\Observability\ExceptionGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ExceptionGroupingTest extends TestCase
{
    use RefreshDatabase;

    public function test_throws_at_different_lines_of_one_function_share_a_group(): void
    {
        $grouper = new ExceptionGrouper(base_path());

        $first = $grouper->describe($this->capture(fn () => $this->renderInvoice(early: true)));
        $second = $grouper->describe($this->capture(fn () => $this->renderInvoice(early: false)));

        $this->assertNotSame($first['line'], $second['line']);
        $this->assertSame($first['fingerprint'], $second['fingerprint']);
    }

    public function test_the_same_class_from_another_function_is_a_separate_group(): void
    {
        $grouper = new ExceptionGrouper(base_path());

        $render = $grouper->describe($this->capture(fn () => $this->renderInvoice(early: true)));
        $send = $grouper->describe($this->capture(fn () => $this->sendInvoice()));

        $this->assertNotSame($render['fingerprint'], $send['fingerprint']);
    }

    public function test_a_converted_php_warning_is_grouped_under_the_function_that_raised_it(): void
    {
        $customer = Customer::factory()->legacyRegion()->create();
        $invoice = Invoice::factory()->withLines(1)->create(['customer_id' => $customer->id]);

        $this->getJson("/ledgerline/invoices/{$invoice->number}/pdf")->assertServerError();

        // The simulator seeds its groups with this formula, so live occurrences join them.
        $this->assertSame(
            ExceptionGrouper::fingerprint('ErrorException', 'app/Ledgerline/InvoiceRenderer.php', 'App\Ledgerline\InvoiceRenderer->render'),
            DB::table('obs_exception_groups')->value('fingerprint'),
        );
    }

    public function test_paths_are_relative_to_a_base_path_that_also_appears_inside_them(): void
    {
        // A Docker image at /app holds the code in /app/app/...
        $exception = new class('Missing VAT rate') extends RuntimeException
        {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->file = '/app/app/Ledgerline/InvoiceRenderer.php';
                $this->line = 21;
            }
        };

        $described = (new ExceptionGrouper('/app'))->describe($exception);

        $this->assertSame('app/Ledgerline/InvoiceRenderer.php', $described['file']);
        $this->assertSame('app/Ledgerline/InvoiceRenderer.php', $described['trace'][0]['file']);
        $this->assertTrue($described['trace'][0]['app']);
    }

    private function capture(callable $callback): Throwable
    {
        try {
            $callback();
        } catch (Throwable $e) {
            return $e;
        }

        $this->fail('Expected an exception.');
    }

    private function renderInvoice(bool $early): never
    {
        if ($early) {
            throw new RuntimeException('Missing VAT rate for region XI');
        }

        throw new RuntimeException('Missing VAT rate for region XI');
    }

    private function sendInvoice(): never
    {
        throw new RuntimeException('Missing VAT rate for region XI');
    }
}
