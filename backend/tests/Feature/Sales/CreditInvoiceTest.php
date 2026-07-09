<?php

namespace Tests\Feature\Sales;

use App\Exceptions\Sales\InvalidReturnException;
use App\Models\Invoice;
use App\Models\StockLevel;
use App\Services\Sales\CheckoutService;
use App\Services\Sales\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPosTestData;
use Tests\TestCase;

class CreditInvoiceTest extends TestCase
{
    use CreatesPosTestData, RefreshDatabase;

    public function test_full_return_neutralizes_the_original_sale_and_restores_stock(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch);
        $product = $this->makeProduct(['price_excl_tax' => '200.00', 'tax_rate' => '18.00', 'track_stock' => true]);
        $cashier = $this->makeUser('cashier', $branch);
        $manager = $this->makeUser('manager', $branch);

        $sale = app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '944.00']],
        ], $cashier);

        $item = $sale->items->first();

        $credit = app(ReturnService::class)->createReturn([
            'original_invoice_id' => $sale->id,
            'terminal_id' => $terminal->id,
            'lines' => [['invoice_item_id' => $item->id, 'quantity' => '4']],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '944.00']],
        ], $manager);

        $this->assertSame(Invoice::TYPE_CREDIT, $credit->invoice_type);
        $this->assertSame($sale->id, $credit->ref_invoice_id);
        $this->assertSame((string) $sale->usin, $credit->refUsinValue());
        $this->assertSame('944.00', (string) $credit->total_bill_amount);
        $this->assertSame(Invoice::FISCAL_SYNCED, $credit->fresh()->fiscal_status);

        $stock = StockLevel::where('product_id', $product->id)->first();
        $this->assertSame('0.000', (string) $stock->quantity); // -4 (sale) + 4 (return) = 0
    }

    public function test_partial_return_computes_proportional_tax_and_discount(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch);
        $product = $this->makeProduct(['price_excl_tax' => '1000.00', 'tax_rate' => '18.00']);
        $cashier = $this->makeUser('cashier', $branch);
        $manager = $this->makeUser('manager', $branch);

        $sale = app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '2360.00']],
        ], $cashier);

        $item = $sale->items->first();

        $credit = app(ReturnService::class)->createReturn([
            'original_invoice_id' => $sale->id,
            'terminal_id' => $terminal->id,
            'lines' => [['invoice_item_id' => $item->id, 'quantity' => '1']],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '1180.00']],
        ], $manager);

        $this->assertSame('1000.00', (string) $credit->total_sale_value);
        $this->assertSame('180.00', (string) $credit->total_tax_charged);
        $this->assertSame('1180.00', (string) $credit->total_bill_amount);
    }

    public function test_cannot_return_more_than_was_sold(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch);
        $product = $this->makeProduct(['price_excl_tax' => '100.00', 'tax_rate' => '0.00']);
        $cashier = $this->makeUser('cashier', $branch);
        $manager = $this->makeUser('manager', $branch);

        $sale = app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '200.00']],
        ], $cashier);

        $item = $sale->items->first();

        $this->expectException(InvalidReturnException::class);

        app(ReturnService::class)->createReturn([
            'original_invoice_id' => $sale->id,
            'terminal_id' => $terminal->id,
            'lines' => [['invoice_item_id' => $item->id, 'quantity' => '3']],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '300.00']],
        ], $manager);
    }

    public function test_two_partial_returns_cannot_together_exceed_original_quantity(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch);
        $product = $this->makeProduct(['price_excl_tax' => '100.00', 'tax_rate' => '0.00']);
        $cashier = $this->makeUser('cashier', $branch);
        $manager = $this->makeUser('manager', $branch);

        $sale = app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '200.00']],
        ], $cashier);

        $item = $sale->items->first();

        app(ReturnService::class)->createReturn([
            'original_invoice_id' => $sale->id,
            'terminal_id' => $terminal->id,
            'lines' => [['invoice_item_id' => $item->id, 'quantity' => '1']],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '100.00']],
        ], $manager);

        $this->expectException(InvalidReturnException::class);

        app(ReturnService::class)->createReturn([
            'original_invoice_id' => $sale->id,
            'terminal_id' => $terminal->id,
            'lines' => [['invoice_item_id' => $item->id, 'quantity' => '2']],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '200.00']],
        ], $manager);
    }

    public function test_cannot_return_a_credit_invoice(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch);
        $product = $this->makeProduct(['price_excl_tax' => '100.00', 'tax_rate' => '0.00']);
        $cashier = $this->makeUser('cashier', $branch);
        $manager = $this->makeUser('manager', $branch);

        $sale = app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '100.00']],
        ], $cashier);

        $credit = app(ReturnService::class)->createReturn([
            'original_invoice_id' => $sale->id,
            'terminal_id' => $terminal->id,
            'lines' => [['invoice_item_id' => $sale->items->first()->id, 'quantity' => '1']],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '100.00']],
        ], $manager);

        $this->expectException(InvalidReturnException::class);

        app(ReturnService::class)->createReturn([
            'original_invoice_id' => $credit->id,
            'terminal_id' => $terminal->id,
            'lines' => [['invoice_item_id' => $credit->items->first()->id, 'quantity' => '1']],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '100.00']],
        ], $manager);
    }
}
