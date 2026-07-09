<?php

namespace Tests\Feature\Fiscal;

use App\Models\FiscalOutbox;
use App\Models\Invoice;
use App\Services\Sales\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPosTestData;
use Tests\TestCase;

/**
 * End-to-end: checkout -> USIN -> invoice/items persisted -> outbox created ->
 * mock fiscalizer submission -> invoice marked synced. QUEUE_CONNECTION=sync in
 * the testing environment means FiscalizeInvoiceJob runs inline, so this single
 * test exercises the whole pipeline without a separate queue worker process.
 */
class CheckoutMockFiscalizerTest extends TestCase
{
    use CreatesPosTestData, RefreshDatabase;

    public function test_single_item_sale_is_created_and_fiscalized_via_mock(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch);
        $product = $this->makeProduct(['price_excl_tax' => '1000.00', 'tax_rate' => '18.00']);
        $cashier = $this->makeUser('cashier', $branch);

        $invoice = app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
            'tenders' => [
                ['mode' => Invoice::PAYMENT_CASH, 'amount' => '2360.00'],
            ],
        ], $cashier);

        $this->assertSame(1, $invoice->usin);
        $this->assertSame('2000.00', (string) $invoice->total_sale_value);
        $this->assertSame('360.00', (string) $invoice->total_tax_charged);
        $this->assertSame('2360.00', (string) $invoice->total_bill_amount);
        $this->assertSame(Invoice::FISCAL_SYNCED, $invoice->fresh()->fiscal_status);
        $this->assertNotNull($invoice->fresh()->fbr_invoice_number);
        $this->assertMatchesRegularExpression('/^\d{6}-\d{12}-\d{4}$/', $invoice->fresh()->fbr_invoice_number);

        $outbox = FiscalOutbox::where('invoice_id', $invoice->id)->first();
        $this->assertSame(FiscalOutbox::STATUS_SUCCESS, $outbox->status);
        $this->assertSame(1, $outbox->attempts);
    }

    public function test_mixed_tender_sale_records_payment_breakdown(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch);
        $product = $this->makeProduct(['price_excl_tax' => '100.00', 'tax_rate' => '0.00']);
        $cashier = $this->makeUser('cashier', $branch);

        $invoice = app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [
                ['mode' => Invoice::PAYMENT_CASH, 'amount' => '60.00'],
                ['mode' => Invoice::PAYMENT_CARD, 'amount' => '40.00'],
            ],
        ], $cashier);

        $this->assertSame(Invoice::PAYMENT_MIXED, $invoice->payment_mode);
        $this->assertCount(2, $invoice->payment_breakdown);
    }

    public function test_stock_is_decremented_for_stock_tracked_products(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch);
        $product = $this->makeProduct(['track_stock' => true, 'price_excl_tax' => '100.00', 'tax_rate' => '18.00']);
        $cashier = $this->makeUser('cashier', $branch);

        app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '354.00']],
        ], $cashier);

        $this->assertDatabaseHas('stock_levels', [
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => -3,
        ]);
    }
}
