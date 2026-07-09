<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Terminal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Terminal>
 */
class TerminalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'code' => 'T' . $this->faker->unique()->numberBetween(1, 999),
            'name' => 'Counter ' . $this->faker->numberBetween(1, 20),
            'fbr_pos_id' => $this->faker->unique()->numberBetween(10000, 99999),
            'fiscal_mode' => 'mock',
            'is_active' => true,
        ];
    }
}
