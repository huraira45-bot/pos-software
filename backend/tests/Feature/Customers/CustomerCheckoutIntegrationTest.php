<?php

namespace Tests\Feature\Customers;

use App\Exceptions\Sales\NonAtlConfirmationRequiredException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Sales\CheckoutService;
use Illuminate\Auth\Access\AuthorizationException;
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
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '1180.00']],
        ], $cashier);

        $this->assertNull($invoice->customer_id);
        $this->assertNull($invoice->buyer_name);
    }

    public function test_attaching_a_customer_populates_buyer_fields_from_the_customer_record(): void
    {
        [$branch, $terminal, $product, $cashier] = $this->setupSale();
        $customer = Customer::factory()->b2b()->atlActive()->create([
            'name' => 'Acme Trading Co',
            'phone' => '03001234567',
        ]);

        $invoice = app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '1180.00']],
            'customer_id' => $customer->id,
        ], $cashier);

        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertSame('Acme Trading Co', $invoice->buyer_name);
        $this->assertSame($customer->formattedNtn(), $invoice->buyer_ntn);
        $this->assertSame('03001234567', $invoice->buyer_phone);
    }

    public function test_non_atl_b2b_customer_requires_confirmation_before_checkout_proceeds(): void
    {
        [$branch, $terminal, $product, $cashier] = $this->setupSale();
        $customer = Customer::factory()->b2b()->atlInactive()->create();

        $this->expectException(NonAtlConfirmationRequiredException::class);

        app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '1180.00']],
            'customer_id' => $customer->id,
        ], $cashier);
    }

    public function test_confirmed_non_atl_b2b_sale_applies_further_tax(): void
    {
        [$branch, $terminal, $product, $cashier] = $this->setupSale();
        $customer = Customer::factory()->b2b()->atlInactive()->create();

        config(['pos.further_tax_rate_percent' => 4]);

        // sale value 1000, tax 18% = 180, further tax 4% of 1000 = 40, total = 1220
        $invoice = app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '1220.00']],
            'customer_id' => $customer->id,
            'confirm_non_atl_b2b' => true,
        ], $cashier);

        $this->assertSame('40.00', (string) $invoice->further_tax);
        $this->assertSame('1220.00', (string) $invoice->total_bill_amount);
        $this->assertTrue((bool) $invoice->non_atl_confirmed);
        $this->assertFalse((bool) $invoice->further_tax_waived);
    }

    public function test_cashier_cannot_waive_further_tax_without_permission(): void
    {
        [$branch, $terminal, $product, $cashier] = $this->setupSale();
        $customer = Customer::factory()->b2b()->atlInactive()->create();

        $this->expectException(AuthorizationException::class);

        app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '1180.00']],
            'customer_id' => $customer->id,
            'confirm_non_atl_b2b' => true,
            'waive_further_tax' => true,
        ], $cashier);
    }

    public function test_manager_can_waive_further_tax_with_permission(): void
    {
        [$branch, $terminal, $product, ] = $this->setupSale();
        $manager = $this->makeUser('manager', $branch);
        $customer = Customer::factory()->b2b()->atlInactive()->create();

        $invoice = app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '1180.00']],
            'customer_id' => $customer->id,
            'confirm_non_atl_b2b' => true,
            'waive_further_tax' => true,
        ], $manager);

        $this->assertSame('0.00', (string) $invoice->further_tax);
        $this->assertTrue((bool) $invoice->further_tax_waived);
    }

    public function test_atl_active_b2b_customer_needs_no_confirmation_and_no_further_tax(): void
    {
        [$branch, $terminal, $product, $cashier] = $this->setupSale();
        $customer = Customer::factory()->b2b()->atlActive()->create();

        $invoice = app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '1180.00']],
            'customer_id' => $customer->id,
        ], $cashier);

        $this->assertSame('0.00', (string) $invoice->further_tax);
        $this->assertFalse((bool) $invoice->non_atl_confirmed);
    }
}
