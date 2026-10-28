<?php

namespace Database\Factories;

use App\Models\Ledgerline\Invoice;
use App\Models\Ledgerline\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    protected $model = InvoiceLine::class;

    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'description' => fake()->randomElement(['Consulting', 'Support plan', 'Hosting', 'Licence seat', 'Onboarding', 'Training day']),
            'quantity' => fake()->numberBetween(1, 12),
            'unit_cents' => fake()->numberBetween(20, 900) * 100,
        ];
    }
}
