<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One-off setup for the dedicated FBR/PRAL sandbox test terminal. Idempotent -
 * safe to re-run (firstOrCreate/updateOrCreate throughout).
 *
 * fiscal_token is only set if FBR_SANDBOX_TOKEN is present in the environment -
 * never hardcoded, never logged. If absent, the terminal is left without a
 * token and effectiveFiscalToken() falls back to config('fiscal.default_token')
 * (also empty by default), which will simply fail auth cleanly rather than
 * silently using a wrong credential.
 */
class SandboxTestTerminalSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::where('code', 'LHR-01')->first();

        if (! $branch) {
            $this->command?->error('Expected branch LHR-01 (Main Branch) not found - run DemoSeeder first.');
            return;
        }

        $terminal = Terminal::updateOrCreate(
            ['fbr_pos_id' => 820590],
            [
                'branch_id' => $branch->id,
                'code' => '61AB0185',
                'name' => 'Sandbox Test Terminal',
                'fiscal_mode' => 'fbr_sandbox',
                'fiscal_endpoint_override' => null, // uses config('fiscal.endpoints.fbr_sandbox')
                'is_active' => true,
            ],
        );

        $token = env('FBR_SANDBOX_TOKEN');
        if ($token) {
            $terminal->update(['fiscal_token' => $token]);
            $this->command?->info('Terminal token set from FBR_SANDBOX_TOKEN.');
        } else {
            $this->command?->warn('FBR_SANDBOX_TOKEN not set in .env - terminal has no token yet. Add it and re-run this seeder.');
        }

        $hassan = User::firstOrCreate(
            ['email' => 'hassan.iqbal@pos.test'],
            ['name' => 'Hassan Iqbal', 'password' => 'password', 'branch_id' => $branch->id],
        );
        $hassan->syncRoles(['cashier']);

        $this->command?->info("Sandbox terminal ready: terminal_id={$terminal->id}, fbr_pos_id=820590, branch={$branch->name}, operator=hassan.iqbal@pos.test");
    }
}
