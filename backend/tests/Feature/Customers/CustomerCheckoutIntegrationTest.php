<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Sales\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPosTestData;
use Tests\TestCase;

class CustomerCheckoutIntegrationTest extends TestCase
{
    use CreatesPosTestData, RefreshDatabase;

    private function setupSale(): array
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch);
        $product = $this->makeProduct(['price_excl_tax' => '1000.00', 'tax_rate' => '18.00']);
        $cashier = $this->makeUser('cashier', $branch);

        return [$branch, $terminal, $product, $cashier];
    }

    public function test_walk_in_checkout_needs_no_customer_and_no_extra_clicks(): void
    {
        [$branch, $terminal, $product, $cashier] = $this->setupSale();

        $invoice = app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'usin_type' => 'SIR',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '1180.00']],
        ], $cashier);

        $this->assertNull($invoice->customer_id);
        $this->assertNull($invoice->buyer_name);
    }

    public function test_attaching_a_customer_populates_buyer_fields_from_the_customer_record(): void
    {
        [$branch, $terminal, $product, $cashier] = $this->setupSale();
        $customer = Customer::factory()->b2b()->create([
            'name' => 'Acme Trading Co',
            'phone' => '03001234567',
        ]);

        $invoice = app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'usin_type' => 'SIR',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '1180.00']],
            'customer_id' => $customer->id,
        ], $cashier);

        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertSame('Acme Trading Co', $invoice->buyer_name);
        $this->assertSame($customer->formattedNtn(), $invoice->buyer_ntn);
        $this->assertSame('03001234567', $invoice->buyer_phone);
    }

}
