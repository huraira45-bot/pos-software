<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'item_code' => strtoupper($this->faker->unique()->bothify('SKU-####')),
            'name' => $this->faker->words(2, true),
            'unit' => 'pcs',
            'pct_code' => $this->faker->numerify('####.####'),
            'tax_rate' => 18.00,
            'price_excl_tax' => $this->faker->randomFloat(2, 50, 5000),
            'track_stock' => true,
            'is_active' => true,
        ];
    }
}
