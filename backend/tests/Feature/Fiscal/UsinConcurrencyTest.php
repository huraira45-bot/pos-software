<?php

namespace Tests\Feature\Fiscal;

use App\Models\Branch;
use App\Models\Terminal;
use App\Models\UsinCounter;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Deliberately does NOT use RefreshDatabase: that trait wraps each test in a
 * transaction that's rolled back at the end, but usin:concurrency-test spawns
 * real, separate OS processes, each with its own DB connection - they would
 * never see this test's uncommitted branch/terminal rows. Data is committed
 * for real and cleaned up explicitly in tearDown() instead.
 */
class UsinConcurrencyTest extends TestCase
{
    private ?Terminal $terminal = null;
    private ?Branch $branch = null;

    protected function tearDown(): void
    {
        if ($this->terminal) {
            UsinCounter::where('terminal_id', $this->terminal->id)->delete();
            $this->terminal->delete();
        }
        $this->branch?->delete();

        parent::tearDown();
    }

    public function test_concurrent_allocations_across_real_processes_are_gapless_and_unique(): void
    {
        $this->branch = Branch::factory()->create();
        $this->terminal = Terminal::factory()->create(['branch_id' => $this->branch->id]);

        $exitCode = Artisan::call('usin:concurrency-test', [
            'terminal' => $this->terminal->id,
            '--n' => 20,
            '--delay-ms' => 20,
        ]);

        // Artisan::output() is unreliable when a proc_open-spawning command is
        // invoked via Artisan::call() from inside PHPUnit - the exit code and
        // the actual counter value are the real assertions; usin:allocate
        // (the child process) exits non-zero on any failure, which
        // usin:concurrency-test propagates, so exit code 0 already implies
        // every one of the 20 child allocations succeeded distinctly.
        $this->assertSame(0, $exitCode);
        $this->assertSame(20, UsinCounter::where('terminal_id', $this->terminal->id)->value('last_value'));
    }
}
