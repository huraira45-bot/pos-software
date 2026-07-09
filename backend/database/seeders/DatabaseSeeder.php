<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // Deliberately NOT using WithoutModelEvents: TerminalObserver provisions
    // each terminal's usin_counters row on the `created` event, and that
    // invariant must hold for seeded data exactly as it does in production.

    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
