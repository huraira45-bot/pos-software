<?php

namespace App\Console\Commands\Usin;

use App\Services\Fiscal\UsinGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Allocates a single USIN for a terminal and prints it. Intentionally a standalone
 * artisan invocation (own process, own DB connection) so it can be spawned
 * concurrently by UsinConcurrencyTestCommand to exercise real Postgres row locking
 * - something a single-process PHPUnit test cannot faithfully reproduce.
 */
class AllocateUsinCommand extends Command
{
    protected $signature = 'usin:allocate {terminal : Terminal ID} {--delay-ms=0 : Sleep inside the transaction before commit, to widen the lock window}';

    protected $description = 'Allocate one USIN for the given terminal inside a DB transaction and print it';

    public function handle(UsinGenerator $generator): int
    {
        $terminalId = (int) $this->argument('terminal');
        $delayMs = (int) $this->option('delay-ms');

        try {
            $usin = DB::transaction(function () use ($generator, $terminalId, $delayMs) {
                $value = $generator->next($terminalId);
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
                return $value;
            });
        } catch (\Throwable $e) {
            Log::error('usin:allocate failed', ['terminal_id' => $terminalId, 'error' => $e->getMessage()]);
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->line((string) $usin);
        return self::SUCCESS;
    }
}
