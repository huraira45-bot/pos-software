<?php

namespace Tests\Feature\Api;

use App\Models\Invoice;
use App\Services\Sales\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPosTestData;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use CreatesPosTestData, RefreshDatabase;

    public function test_cashier_without_reports_permission_is_forbidden(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $cashier = $this->makeUser('cashier', $branch);

        $this->actingAs($cashier)
            ->getJson('/api/reports/sales-summary')
            ->assertForbidden();
    }

    public function test_manager_with_reports_permission_can_view(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $manager = $this->makeUser('manager', $branch);

        $this->actingAs($manager)
            ->getJson('/api/reports/sales-summary')
            ->assertOk()
            ->assertJsonStructure(['summary', 'columns', 'rows', 'totals', 'meta']);
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $manager = $this->makeUser('manager', $branch);

        $this->actingAs($manager)
            ->getJson('/api/reports/sales-summary?from=2026-01-10&to=2026-01-01')
            ->assertStatus(422);
    }

    public function test_sales_by_item_returns_the_standard_report_envelope(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch);
        $cashier = $this->makeUser('cashier', $branch);
        $manager = $this->makeUser('manager', $branch);
        $product = $this->makeProduct(['price_excl_tax' => '200.00', 'tax_rate' => '0.00']);

        app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'usin_type' => 'SIR',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '200.00']],
        ], $cashier);

        $this->actingAs($manager)
            ->getJson('/api/reports/sales-by-item?branch_id=' . $branch->id)
            ->assertOk()
            ->assertJsonStructure(['summary', 'columns', 'rows', 'totals', 'meta'])
            ->assertJsonPath('rows.0.item_code', $product->item_code);
    }

    public function test_b2b_statement_requires_customer_id(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $manager = $this->makeUser('manager', $branch);

        $this->actingAs($manager)
            ->getJson('/api/reports/b2b-statement')
            ->assertStatus(422);
    }

    public function test_dashboard_summary_is_gated_the_same_as_reports(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $cashier = $this->makeUser('cashier', $branch);
        $manager = $this->makeUser('manager', $branch);

        $this->actingAs($cashier)->getJson('/api/dashboard/summary')->assertForbidden();
        $this->actingAs($manager)->getJson('/api/dashboard/summary')->assertOk();
    }
}
