<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('BR???')),
            'name' => $this->faker->company() . ' Branch',
            'address_line1' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'ntn' => $this->faker->numerify('#######-#'),
            'strn' => $this->faker->numerify('##-##-####-###-##'),
            'tax_office_name' => 'RTO ' . $this->faker->city(),
            'phone' => $this->faker->phoneNumber(),
            'is_active' => true,
        ];
    }
}
