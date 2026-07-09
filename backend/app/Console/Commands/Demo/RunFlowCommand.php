<?php

namespace App\Console\Commands\Demo;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Fiscal\FiscalSubmissionService;
use App\Services\Receipt\ReceiptDataBuilder;
use App\Services\Sales\CheckoutService;
use App\Services\Sales\ReturnService;
use Illuminate\Console\Command;

/**
 * End-to-end walkthrough: sale -> fiscalize -> print receipt payload -> return
 * -> credit fiscalize -> print credit receipt payload. Fiscalizes synchronously
 * (calls FiscalSubmissionService directly) so the whole demo is self-contained
 * and doesn't depend on a queue worker already running.
 *
 * Run `php artisan db:seed` first (DemoSeeder) to get the branch/terminal/
 * products/users this expects.
 */
class RunFlowCommand extends Command
{
    protected $signature = 'demo:run-flow';

    protected $description = 'Demo: sale -> fiscalize -> print receipt -> return -> credit fiscalize';

    public function handle(
        CheckoutService $checkout,
        ReturnService $returns,
        FiscalSubmissionService $submission,
        ReceiptDataBuilder $receiptBuilder,
    ): int {
        $branch = Branch::where('code', 'LHR-01')->first();
        $terminal = Terminal::where('branch_id', $branch?->id)->where('code', 'T1')->first();
        $cashier = User::where('email', 'cashier1@pos.test')->first();
        $manager = User::where('email', 'manager@pos.test')->first();
        $tshirt = Product::where('item_code', 'APP-001')->first();
        $kurti = Product::where('item_code', 'APP-002')->first();

        if (! $branch || ! $terminal || ! $cashier || ! $manager || ! $tshirt || ! $kurti) {
            $this->error('Demo data not found. Run `php artisan db:seed` first.');
            return self::FAILURE;
        }

        $this->section('1. Checkout: 2x T-Shirt + 1x Kurti, mixed payment (cash + card)');

        $sale = $checkout->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'items' => [
                ['product_id' => $tshirt->id, 'quantity' => 2],
                ['product_id' => $kurti->id, 'quantity' => 1],
            ],
            'tenders' => [
                ['mode' => Invoice::PAYMENT_CASH, 'amount' => '2000.00'],
                ['mode' => Invoice::PAYMENT_CARD, 'amount' => (string) bcsub($this->grossTotal($tshirt, 2, $kurti, 1), '2000.00', 2)],
            ],
        ], $cashier);

        $this->info("Sale created: USIN {$sale->usin}, total Rs.{$sale->total_bill_amount}, fiscal_status={$sale->fiscal_status}");

        $this->section('2. Fiscalize (synchronous, for this demo)');
        $outbox = $sale->fiscalOutbox()->firstOrFail();
        $outcome = $submission->submit($outbox->id, 'demo-script');
        $sale->refresh();
        $this->info("Outcome: {$outcome->status} | FBR Invoice No: {$sale->fbr_invoice_number}");

        $this->section('3. Receipt payload (what the print-agent would render)');
        $this->line(json_encode($receiptBuilder->build($sale), JSON_PRETTY_PRINT));

        $this->section('4. Partial return: 1x T-Shirt (of the 2 originally sold)');
        $tshirtLine = $sale->items->firstWhere('item_code', 'APP-001');
        // The line covers 2 units - refund exactly the per-unit share for the 1 being returned.
        $refundAmount = bcdiv((string) $tshirtLine->total_amount, (string) $tshirtLine->quantity, 2);
        $credit = $returns->createReturn([
            'original_invoice_id' => $sale->id,
            'terminal_id' => $terminal->id,
            'lines' => [['invoice_item_id' => $tshirtLine->id, 'quantity' => '1']],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => $refundAmount]],
        ], $manager);

        $this->info("Credit invoice created: USIN {$credit->usin}, RefUSIN {$credit->refUsinValue()}, total Rs.{$credit->total_bill_amount}");

        $this->section('5. Fiscalize the credit invoice');
        $creditOutbox = $credit->fiscalOutbox()->firstOrFail();
        $creditOutcome = $submission->submit($creditOutbox->id, 'demo-script');
        $credit->refresh();
        $this->info("Outcome: {$creditOutcome->status} | FBR Invoice No: {$credit->fbr_invoice_number}");

        $this->section('6. Credit receipt payload');
        $this->line(json_encode($receiptBuilder->build($credit), JSON_PRETTY_PRINT));

        $this->newLine();
        $this->info('Demo flow complete.');

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->info("=== {$title} ===");
    }

    private function grossTotal(Product $a, int $qtyA, Product $b, int $qtyB): string
    {
        $lineA = bcmul(bcmul((string) $a->price_excl_tax, (string) $qtyA, 2), bcadd('1', bcdiv((string) $a->tax_rate, '100', 4), 4), 2);
        $lineB = bcmul(bcmul((string) $b->price_excl_tax, (string) $qtyB, 2), bcadd('1', bcdiv((string) $b->tax_rate, '100', 4), 4), 2);

        return bcadd($lineA, $lineB, 2);
    }
}
