<?php

namespace Tests\Feature\Fiscal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesPosTestData;
use Tests\TestCase;

/**
 * Validates fiscal:sandbox-smoke-test's own logic (correct amounts, correct
 * scenario wiring, refusal to run against non-sandbox terminals) using
 * Http::fake() to simulate a healthy PRAL sandbox - this cannot exercise real
 * PRAL connectivity/credentials, only that our side of every scenario in the
 * brief's Definition of Done is constructed and submitted correctly.
 */
class SandboxSmokeTestCommandTest extends TestCase
{
    use CreatesPosTestData, RefreshDatabase;

    public function test_refuses_to_run_without_confirm_flag(): void
    {
        $this->seedRolesAndPermissions();
        $terminal = $this->makeTerminal(null, ['fiscal_mode' => 'fbr_sandbox']);

        $this->artisan('fiscal:sandbox-smoke-test', ['terminal' => $terminal->id])
            ->assertExitCode(1);
    }

    public function test_refuses_to_run_against_non_sandbox_terminal(): void
    {
        $this->seedRolesAndPermissions();
        $terminal = $this->makeTerminal(null, ['fiscal_mode' => 'mock']);

        $this->artisan('fiscal:sandbox-smoke-test', ['terminal' => $terminal->id, '--confirm' => true])
            ->assertExitCode(1);
    }

    public function test_all_scenarios_pass_against_a_healthy_faked_sandbox(): void
    {
        $this->seedRolesAndPermissions();
        $terminal = $this->makeTerminal(null, ['fiscal_mode' => 'fbr_sandbox']);
        $this->makeUser('manager', $terminal->branch);

        $sequence = 0;
        Http::fake(function () use (&$sequence) {
            $sequence++;
            return Http::response([
                'InvoiceNumber' => sprintf('%06d-080726120000-%04d', 50000, $sequence),
                'Response' => 'Invoice received successfully',
                'Code' => '100',
            ], 200);
        });

        $exit = Artisan::call('fiscal:sandbox-smoke-test', ['terminal' => $terminal->id, '--confirm' => true]);
        $this->assertSame(0, $exit, Artisan::output());

        Http::assertSentCount(7); // 5 new sales + full return + partial return
    }
}
