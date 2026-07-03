<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Buyer>
 */
class BuyerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name_buyer' => $this->faker->name(),
            'phone_buyer' => $this->faker->phoneNumber(),
            'address_buyer' => $this->faker->address(),
            'type_buyer' => "biasa",
            'amount_transaction_buyer' => 1000.0,
            'amount_purchase_buyer' => 1000.0,
            'avg_purchase_buyer' => 1000.0,

        ];
    }
}
