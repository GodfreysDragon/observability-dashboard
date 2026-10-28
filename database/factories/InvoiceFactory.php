<?php

namespace Database\Factories;

use App\Models\Ledgerline\Customer;
use App\Models\Ledgerline\Invoice;
use App\Models\Ledgerline\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $issued = fake()->dateTimeBetween('-120 days', 'now');

        return [
            'customer_id' => Customer::factory(),
            'number' => 'INV-'.fake()->unique()->numberBetween(100000, 999999),
            'status' => fake()->randomElement(['draft', 'sent', 'sent', 'paid', 'paid', 'overdue']),
            'issued_on' => $issued,
            'due_on' => (clone $issued)->modify('+30 days'),
            'total_cents' => 0,
        ];
    }

    public function withLines(int $count = 3): static
    {
        return $this->afterCreating(function (Invoice $invoice) use ($count) {
            $lines = InvoiceLine::factory()->count($count)->create(['invoice_id' => $invoice->id]);
            $invoice->update(['total_cents' => $lines->sum(fn (InvoiceLine $line) => $line->quantity * $line->unit_cents)]);
        });
    }
}
