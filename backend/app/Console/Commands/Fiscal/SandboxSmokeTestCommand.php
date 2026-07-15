<?php

namespace App\Console\Commands\Fiscal;

use App\Models\Invoice;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Fiscal\FiscalSubmissionService;
use App\Services\Sales\CheckoutService;
use App\Services\Sales\ReturnService;
use Illuminate\Console\Command;

/**
 * Posts a fixed battery of sample invoices to the PRAL sandbox (never
 * production - refuses to run against anything but fiscal_mode=fbr_sandbox)
 * and verifies FBR's response for each, covering every scenario the brief's
 * Definition of Done lists: single-item sale, multi-item sale, discounted
 * sale, mixed payment, B2B sale with buyer NTN, full return, partial return.
 *
 * Requires a terminal already registered with PRAL (real fbr_pos_id + token)
 * and pointed at fiscal_mode=fbr_sandbox. Every invoice this creates is real
 * committed data (usable afterward for reconciliation testing) - it does not
 * roll back, since the whole point is to prove the real pipeline end to end.
 */
class SandboxSmokeTestCommand extends Command
{
    protected $signature = 'fiscal:sandbox-smoke-test
        {terminal : Terminal ID pre-configured with fiscal_mode=fbr_sandbox and a real PRAL token}
        {--confirm : Required - this posts real HTTP requests to the PRAL sandbox}';

    protected $description = 'Post sample New and Credit invoices to the FBR sandbox and verify responses';

    private array $results = [];

    public function handle(CheckoutService $checkout, ReturnService $returns, FiscalSubmissionService $submission): int
    {
        $terminal = Terminal::find($this->argument('terminal'));
        if (! $terminal) {
            $this->error('Terminal not found.');
            return self::FAILURE;
        }

        if ($terminal->effectiveFiscalMode() !== 'fbr_sandbox') {
            $this->error(
                "Refusing to run: terminal {$terminal->code} resolves to fiscal_mode="
                . "'{$terminal->effectiveFiscalMode()}', not 'fbr_sandbox'. This command "
                . 'will only ever target the sandbox, never production.'
            );
            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->warn("This will POST real HTTP requests to {$terminal->effectiveFiscalMode()} for terminal {$terminal->code}.");
            $this->warn('Re-run with --confirm to proceed.');
            return self::FAILURE;
        }

        // Needs RETURNS_CREATE (manager/admin) since this exercises both New
        // and Credit invoice scenarios - a plain cashier account would fail
        // partway through on the return scenarios.
        $actor = User::role(['admin', 'manager'])->first();
        if (! $actor) {
            $this->error('No admin/manager user found to act as operator - seed one first (returns require that permission).');
            return self::FAILURE;
        }

        $product = $this->findOrCreateSmokeTestProduct();

        $this->runScenario('New sale - single item', function () use ($checkout, $submission, $terminal, $product, $actor) {
            $invoice = $checkout->checkout([
                'branch_id' => $terminal->branch_id,
                'terminal_id' => $terminal->id,
                'usin_type' => 'SIR',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '236.00']],
            ], $actor);

            return $this->submitAndVerify($submission, $invoice);
        });

        $multiItemInvoice = null;
        $this->runScenario('New sale - multi item', function () use ($checkout, $submission, $terminal, $product, $actor, &$multiItemInvoice) {
            $multiItemInvoice = $checkout->checkout([
                'branch_id' => $terminal->branch_id,
                'terminal_id' => $terminal->id,
                'usin_type' => 'SIR',
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2],
                    ['product_id' => $product->id, 'quantity' => 3],
                ],
                'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '1180.00']],
            ], $actor);

            return $this->submitAndVerify($submission, $multiItemInvoice);
        });

        $this->runScenario('Discounted sale (bill discount)', function () use ($checkout, $submission, $terminal, $product, $actor) {
            $invoice = $checkout->checkout([
                'branch_id' => $terminal->branch_id,
                'terminal_id' => $terminal->id,
                'usin_type' => 'SIR',
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'bill_discount' => '20.00',
                'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '448.40']],
            ], $actor);

            return $this->submitAndVerify($submission, $invoice);
        });

        $this->runScenario('Mixed payment sale', function () use ($checkout, $submission, $terminal, $product, $actor) {
            $invoice = $checkout->checkout([
                'branch_id' => $terminal->branch_id,
                'terminal_id' => $terminal->id,
                'usin_type' => 'SIR',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'tenders' => [
                    ['mode' => Invoice::PAYMENT_CASH, 'amount' => '136.00'],
                    ['mode' => Invoice::PAYMENT_CARD, 'amount' => '100.00'],
                ],
            ], $actor);

            return $this->submitAndVerify($submission, $invoice);
        });

        $this->runScenario('B2B sale with buyer NTN (>Rs.100,000)', function () use ($checkout, $submission, $terminal, $product, $actor) {
            $invoice = $checkout->checkout([
                'branch_id' => $terminal->branch_id,
                'terminal_id' => $terminal->id,
                'usin_type' => 'SIR',
                'items' => [['product_id' => $product->id, 'quantity' => 500]],
                'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '118000.00']],
                'buyer' => ['ntn' => '1234567-8', 'name' => 'Smoke Test Buyer (Pvt) Ltd'],
            ], $actor);

            return $this->submitAndVerify($submission, $invoice);
        });

        $singleItemInvoice = Invoice::where('terminal_id', $terminal->id)
            ->where('invoice_type', Invoice::TYPE_NEW)
            ->orderBy('id')
            ->first();

        $this->runScenario('Full return', function () use ($returns, $submission, $terminal, $singleItemInvoice, $actor) {
            $credit = $returns->createReturn([
                'original_invoice_id' => $singleItemInvoice->id,
                'terminal_id' => $terminal->id,
                'lines' => [['invoice_item_id' => $singleItemInvoice->items->first()->id, 'quantity' => (string) $singleItemInvoice->items->first()->quantity]],
                'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => (string) $singleItemInvoice->total_bill_amount]],
            ], $actor);

            return $this->submitAndVerify($submission, $credit);
        });

        $this->runScenario('Partial return', function () use ($returns, $submission, $terminal, $multiItemInvoice, $actor) {
            $firstLine = $multiItemInvoice->items->first();
            $refundAmount = bcmul(
                bcmul((string) $firstLine->unit_price_excl_tax, '1', 2),
                bcadd('1', bcdiv((string) $firstLine->tax_rate, '100', 4), 4),
                2,
            );

            $credit = $returns->createReturn([
                'original_invoice_id' => $multiItemInvoice->id,
                'terminal_id' => $terminal->id,
                'lines' => [['invoice_item_id' => $firstLine->id, 'quantity' => '1']],
                'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => $refundAmount]],
            ], $actor);

            return $this->submitAndVerify($submission, $credit);
        });

        return $this->report();
    }

    private function submitAndVerify(FiscalSubmissionService $submission, Invoice $invoice): bool
    {
        // Calls submit() explicitly so pass/fail is known immediately rather
        // than waiting on the queue. If QUEUE_CONNECTION=sync (as in this
        // project's default local/test config), CheckoutService/ReturnService's
        // own post-commit dispatch already ran synchronously by this point and
        // the outbox is already `success` - submit() then correctly reports
        // 'already_synced' rather than double-posting, which is exactly the
        // idempotency guarantee at work, not a failure.
        $outbox = $invoice->fiscalOutbox()->firstOrFail();
        $outcome = $submission->submit($outbox->id, 'sandbox-smoke-test');

        $invoice->refresh();
        $ok = in_array($outcome->status, ['synced', 'already_synced'], true) && $invoice->fbr_invoice_number !== null;

        $this->line($ok
            ? "    -> USIN {$invoice->usin}: FBR #{$invoice->fbr_invoice_number}"
            : "    -> USIN {$invoice->usin}: FAILED ({$outcome->status})");

        return $ok;
    }

    private function runScenario(string $name, \Closure $scenario): void
    {
        $this->info("Running: {$name}");

        try {
            $this->results[$name] = $scenario();
        } catch (\Throwable $e) {
            $this->error("    -> Exception: {$e->getMessage()}");
            $this->results[$name] = false;
        }
    }

    private function report(): int
    {
        $this->newLine();
        $this->info('=== Sandbox Smoke Test Results ===');

        $allPassed = true;
        foreach ($this->results as $name => $passed) {
            $this->line(($passed ? '  [PASS] ' : '  [FAIL] ') . $name);
            $allPassed = $allPassed && $passed;
        }

        return $allPassed ? self::SUCCESS : self::FAILURE;
    }

    private function findOrCreateSmokeTestProduct(): \App\Models\Product
    {
        return \App\Models\Product::firstOrCreate(
            ['item_code' => 'SMOKE-TEST-SKU'],
            [
                'name' => 'Smoke Test Item',
                'unit' => 'pcs',
                'pct_code' => '9999.9999',
                'tax_rate' => '18.00',
                'price_excl_tax' => '200.00',
                'track_stock' => false,
            ],
        );
    }
}
