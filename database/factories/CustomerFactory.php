<?php

namespace Database\Factories;

use App\Models\Ledgerline\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'email' => fake()->unique()->companyEmail(),
            'region' => fake()->randomElement(['UK', 'UK', 'UK', 'IE', 'DE', 'FR', 'NL', 'US']),
        ];
    }

    /**
     * A legacy account whose region code is missing from the VAT table.
     */
    public function legacyRegion(): static
    {
        return $this->state(['region' => 'XI']);
    }
}
