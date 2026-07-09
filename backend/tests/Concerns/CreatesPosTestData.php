<?php

namespace Tests\Concerns;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Terminal;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

trait CreatesPosTestData
{
    protected function seedRolesAndPermissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeBranch(array $overrides = []): Branch
    {
        return Branch::factory()->create($overrides);
    }

    protected function makeTerminal(?Branch $branch = null, array $overrides = []): Terminal
    {
        return Terminal::factory()->create([
            'branch_id' => ($branch ?? $this->makeBranch())->id,
            ...$overrides,
        ]);
    }

    protected function makeProduct(array $overrides = []): Product
    {
        return Product::factory()->create($overrides);
    }

    protected function makeUser(string $role, ?Branch $branch = null, array $overrides = []): User
    {
        $user = User::factory()->create([
            'branch_id' => $branch?->id,
            ...$overrides,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
