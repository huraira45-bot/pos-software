<?php

namespace App\Console\Commands\Usin;

use Illuminate\Console\Command;

/**
 * Spawns N real, concurrent OS processes that each allocate one USIN for the
 * same terminal, then asserts the results are exactly {1..N} with no duplicates
 * and no gaps. This proves the SELECT ... FOR UPDATE locking in UsinGenerator
 * actually serializes concurrent writers on Postgres - a guarantee a single-process
 * PHPUnit test using one DB connection cannot exercise.
 */
class UsinConcurrencyTestCommand extends Command
{
    protected $signature = 'usin:concurrency-test {terminal : Terminal ID} {--type=SIR : USIN type - SIR or SS} {--n=25 : Number of concurrent allocations} {--delay-ms=20 : Artificial hold time inside each transaction}';

    protected $description = 'Fire N concurrent USIN allocations at one terminal and verify the sequence is gapless and unique';

    public function handle(): int
    {
        $terminalId = (int) $this->argument('terminal');
        $usinType = (string) $this->option('type');
        $n = (int) $this->option('n');
        $delayMs = (int) $this->option('delay-ms');

        $phpBinary = PHP_BINARY;
        $artisan = base_path('artisan');

        $processes = [];
        for ($i = 0; $i < $n; $i++) {
            $cmd = [$phpBinary, $artisan, 'usin:allocate', (string) $terminalId, "--type={$usinType}", "--delay-ms={$delayMs}"];
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open($cmd, $descriptorSpec, $pipes, base_path());
            if (! is_resource($process)) {
                $this->error("Failed to spawn process #{$i}");
                return self::FAILURE;
            }
            fclose($pipes[0]);
            $processes[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
        }

        $results = [];
        $errors = [];
        foreach ($processes as $p) {
            $out = stream_get_contents($p['stdout']);
            $err = stream_get_contents($p['stderr']);
            fclose($p['stdout']);
            fclose($p['stderr']);
            $exitCode = proc_close($p['process']);

            if ($exitCode !== 0) {
                $errors[] = trim($err) ?: "exit code {$exitCode}";
                continue;
            }
            $rawUsin = trim($out);
            preg_match('/(\d+)$/', $rawUsin, $matches);
            $results[] = isset($matches[1]) ? (int) $matches[1] : 0;
        }

        if (! empty($errors)) {
            $this->error(sprintf('%d/%d processes failed:', count($errors), $n));
            foreach (array_slice($errors, 0, 10) as $e) {
                $this->line("  - {$e}");
            }
            return self::FAILURE;
        }

        sort($results);
        $expected = range(1, $n);
        $isGaplessAndUnique = $results === $expected;

        $this->info("Allocated {$usinType} USIN numbers: " . implode(',', $results));

        if (! $isGaplessAndUnique) {
            $duplicates = array_diff_assoc($results, array_unique($results));
            $missing = array_diff($expected, $results);
            $this->error('USIN sequence is NOT gapless/unique.');
            if (! empty($duplicates)) {
                $this->error('Duplicates: ' . implode(',', array_unique($duplicates)));
            }
            if (! empty($missing)) {
                $this->error('Missing: ' . implode(',', $missing));
            }
            return self::FAILURE;
        }

        $this->info("PASS: {$n} concurrent allocations produced exactly {1..{$n}} with no duplicates or gaps.");
        return self::SUCCESS;
    }
}
