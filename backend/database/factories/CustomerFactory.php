<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'phone' => $this->faker->numerify('03#########'),
            'customer_type' => Customer::TYPE_WALK_IN,
            'atl_status' => Customer::ATL_UNKNOWN,
            'is_active' => true,
        ];
    }

    public function b2b(): static
    {
        return $this->state(fn () => [
            'customer_type' => Customer::TYPE_B2B,
            'ntn' => $this->faker->unique()->numerify('#######'),
        ]);
    }

    public function atlActive(): static
    {
        return $this->state(fn () => [
            'atl_status' => Customer::ATL_ACTIVE,
            'atl_checked_at' => now(),
        ]);
    }

    public function atlInactive(): static
    {
        return $this->state(fn () => [
            'atl_status' => Customer::ATL_INACTIVE,
            'atl_checked_at' => now(),
        ]);
    }
}
