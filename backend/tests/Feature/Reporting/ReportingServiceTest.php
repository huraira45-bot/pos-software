<?php

namespace Tests\Feature\Reporting;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Reporting\ReportingService;
use App\Services\Sales\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPosTestData;
use Tests\TestCase;

class ReportingServiceTest extends TestCase
{
    use CreatesPosTestData, RefreshDatabase;

    private function checkout(array $cart, $actor): Invoice
    {
        return app(CheckoutService::class)->checkout($cart, $actor);
    }

    public function test_profit_by_item_uses_current_cost_price_and_treats_null_cost_as_zero(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch);
        $cashier = $this->makeUser('cashier', $branch);

        $productWithCost = $this->makeProduct(['price_excl_tax' => '150.00', 'tax_rate' => '0.00', 'cost_price' => '100.00']);
        $productNoCost = $this->makeProduct(['price_excl_tax' => '50.00', 'tax_rate' => '0.00', 'cost_price' => null]);

        $this->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'usin_type' => 'SIR',
            'items' => [
                ['product_id' => $productWithCost->id, 'quantity' => 2],
                ['product_id' => $productNoCost->id, 'quantity' => 1],
            ],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '350.00']],
        ], $cashier);

        $report = app(ReportingService::class)->profitByItem(['branch_id' => $branch->id]);
        $byCode = collect($report['rows'])->keyBy('item_code');

        // 'cost' is a raw SQL expression (quantity * coalesce(cost_price,0)), so its
        // string formatting can vary by DB driver - compare numerically. 'revenue' and
        // 'profit' are reliable exact strings since we format them ourselves in PHP.
        $this->assertSame('300.00', $byCode[$productWithCost->item_code]['revenue']);
        $this->assertEqualsWithDelta(200.0, (float) $byCode[$productWithCost->item_code]['cost'], 0.01);
        $this->assertSame('100.00', $byCode[$productWithCost->item_code]['profit']);

        $this->assertSame('50.00', $byCode[$productNoCost->item_code]['revenue']);
        $this->assertEqualsWithDelta(0.0, (float) $byCode[$productNoCost->item_code]['cost'], 0.01);
        $this->assertSame('50.00', $byCode[$productNoCost->item_code]['profit']);

        $this->assertGreaterThan(0, $report['meta']['missing_cost_lines']);
    }

    public function test_sales_by_payment_method_reconciles_cash_card_and_mixed_tenders(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch);
        $cashier = $this->makeUser('cashier', $branch);
        $product = $this->makeProduct(['price_excl_tax' => '1000.00', 'tax_rate' => '0.00']);

        $this->checkout([
            'branch_id' => $branch->id, 'terminal_id' => $terminal->id, 'usin_type' => 'SIR',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '1000.00']],
        ], $cashier);

        $this->checkout([
            'branch_id' => $branch->id, 'terminal_id' => $terminal->id, 'usin_type' => 'SIR',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CARD, 'amount' => '1000.00']],
        ], $cashier);

        $this->checkout([
            'branch_id' => $branch->id, 'terminal_id' => $terminal->id, 'usin_type' => 'SIR',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [
                ['mode' => Invoice::PAYMENT_CASH, 'amount' => '600.00'],
                ['mode' => Invoice::PAYMENT_CARD, 'amount' => '400.00'],
            ],
        ], $cashier);

        $report = app(ReportingService::class)->salesByPaymentMethod(['branch_id' => $branch->id]);
        $byLabel = collect($report['rows'])->keyBy('label');

        $this->assertSame('1600.00', $byLabel['Cash']['total']);
        $this->assertSame('1400.00', $byLabel['Card']['total']);
        $this->assertSame('3000.00', $report['totals']['total']);
    }

    public function test_tax_collected_synced_and_unsynced_sum_to_total(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch);
        $cashier = $this->makeUser('cashier', $branch);
        $product = $this->makeProduct(['price_excl_tax' => '1000.00', 'tax_rate' => '18.00']);

        // FISCAL_MODE=mock + QUEUE_CONNECTION=sync in phpunit.xml -> this invoice is
        // already fiscalized (synced) by the time checkout() returns.
        $this->checkout([
            'branch_id' => $branch->id, 'terminal_id' => $terminal->id, 'usin_type' => 'SIR',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '1180.00']],
        ], $cashier);

        $report = app(ReportingService::class)->taxCollected(['branch_id' => $branch->id]);
        $byKey = collect($report['summary'])->keyBy('key');

        $total = (float) $byKey['total_tax_charged']['value'];
        $synced = (float) $byKey['synced_tax_charged']['value'];
        $unsynced = (float) $byKey['unsynced_tax_charged']['value'];

        $this->assertEqualsWithDelta($total, $synced + $unsynced, 0.01);
        $this->assertSame('180.00', $byKey['total_tax_charged']['value']);
    }

    public function test_sales_summary_nets_new_sales_against_credit_returns(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch);
        $cashier = $this->makeUser('cashier', $branch);

        Invoice::create([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'usin' => 9001,
            'invoice_type' => Invoice::TYPE_NEW,
            'total_sale_value' => '1000.00',
            'total_tax_charged' => '180.00',
            'total_bill_amount' => '1180.00',
            'payment_mode' => Invoice::PAYMENT_CASH,
            'fiscal_status' => Invoice::FISCAL_SYNCED,
            'sold_at' => now(),
            'cashier_id' => $cashier->id,
        ]);

        Invoice::create([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'usin' => 9002,
            'invoice_type' => Invoice::TYPE_CREDIT,
            'total_sale_value' => '200.00',
            'total_tax_charged' => '36.00',
            'total_bill_amount' => '236.00',
            'payment_mode' => Invoice::PAYMENT_CASH,
            'fiscal_status' => Invoice::FISCAL_SYNCED,
            'sold_at' => now(),
            'cashier_id' => $cashier->id,
        ]);

        $report = app(ReportingService::class)->salesSummary(['branch_id' => $branch->id]);

        $this->assertSame('944.00', $report['totals']['total_bill_amount']); // 1180.00 - 236.00
    }

    public function test_sales_by_customer_includes_walk_in_as_its_own_row_reconciling_with_summary(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch);
        $cashier = $this->makeUser('cashier', $branch);
        $product = $this->makeProduct(['price_excl_tax' => '500.00', 'tax_rate' => '0.00']);
        $customer = Customer::factory()->b2b()->create(['name' => 'Acme Co']);

        $this->checkout([
            'branch_id' => $branch->id, 'terminal_id' => $terminal->id, 'usin_type' => 'SIR',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '500.00']],
        ], $cashier);

        $this->checkout([
            'branch_id' => $branch->id, 'terminal_id' => $terminal->id, 'usin_type' => 'SIR',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '500.00']],
            'customer_id' => $customer->id,
        ], $cashier);

        $report = app(ReportingService::class)->salesByCustomer(['branch_id' => $branch->id]);

        $this->assertSame('1000.00', $report['totals']['total_revenue']);
        $names = collect($report['rows'])->pluck('customer_name');
        $this->assertContains('Walk-in', $names);
        $this->assertContains('Acme Co', $names);
    }

    public function test_branch_and_date_filters_isolate_results(): void
    {
        $this->seedRolesAndPermissions();
        $branchA = $this->makeBranch();
        $branchB = $this->makeBranch();
        $terminalA = $this->makeTerminal($branchA);
        $terminalB = $this->makeTerminal($branchB);
        $cashierA = $this->makeUser('cashier', $branchA);
        $cashierB = $this->makeUser('cashier', $branchB);
        $product = $this->makeProduct(['price_excl_tax' => '100.00', 'tax_rate' => '0.00']);

        $this->checkout([
            'branch_id' => $branchA->id, 'terminal_id' => $terminalA->id, 'usin_type' => 'SIR',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '100.00']],
        ], $cashierA);

        $this->checkout([
            'branch_id' => $branchB->id, 'terminal_id' => $terminalB->id, 'usin_type' => 'SIR',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '100.00']],
        ], $cashierB);

        $reportA = app(ReportingService::class)->salesSummary(['branch_id' => $branchA->id]);
        $this->assertSame('100.00', $reportA['totals']['total_bill_amount']);

        $reportFuture = app(ReportingService::class)->salesSummary([
            'from' => now()->addDays(5)->toDateString(),
        ]);
        $this->assertSame('0.00', $reportFuture['totals']['total_bill_amount']);
    }
}
