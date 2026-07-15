<?php

namespace Tests\Feature\Compliance;

use App\Jobs\FiscalizeInvoiceJob;
use App\Models\FiscalOutbox;
use App\Models\Invoice;
use App\Services\Compliance\ComplianceService;
use App\Services\Sales\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesPosTestData;
use Tests\TestCase;

/**
 * Regression coverage for a real bug found during sandbox testing: Carbon 3
 * changed diffInMinutes() to return a signed difference by default, so
 * now()->diffInMinutes($pastTimestamp) returned a NEGATIVE number. Since
 * is_breaching_threshold compared that (always-negative) age against a
 * positive threshold, the compliance dashboard's breach flag could never
 * fire, no matter how long an invoice had been stuck pending.
 */
class ComplianceSyncHealthTest extends TestCase
{
    use CreatesPosTestData, RefreshDatabase;

    public function test_oldest_pending_age_is_reported_as_a_positive_number_of_minutes(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch, ['fiscal_mode' => 'fbr_sandbox']);
        $product = $this->makeProduct(['price_excl_tax' => '100.00', 'tax_rate' => '0.00']);
        $cashier = $this->makeUser('cashier', $branch);

        Queue::fake([FiscalizeInvoiceJob::class]);

        $invoice = app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'usin_type' => 'SIR',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '100.00']],
        ], $cashier);

        $outbox = FiscalOutbox::where('invoice_id', $invoice->id)->firstOrFail();
        $outbox->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();

        $health = collect(app(ComplianceService::class)->syncHealth())
            ->firstWhere('terminal_id', $terminal->id);

        $this->assertGreaterThan(0, $health['oldest_pending_age_minutes']);
        $this->assertIsInt($health['oldest_pending_age_minutes']);
        $this->assertEqualsWithDelta(120, $health['oldest_pending_age_minutes'], 2);
    }

    public function test_breaching_threshold_flag_reacts_to_the_configured_threshold(): void
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch, ['fiscal_mode' => 'fbr_sandbox']);
        $product = $this->makeProduct(['price_excl_tax' => '100.00', 'tax_rate' => '0.00']);
        $cashier = $this->makeUser('cashier', $branch);

        Queue::fake([FiscalizeInvoiceJob::class]);

        $invoice = app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'usin_type' => 'SIR',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '100.00']],
        ], $cashier);

        $outbox = FiscalOutbox::where('invoice_id', $invoice->id)->firstOrFail();
        $outbox->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();

        config(['fiscal.pending_alert_threshold_minutes' => 60]);
        $breaching = collect(app(ComplianceService::class)->syncHealth())->firstWhere('terminal_id', $terminal->id);
        $this->assertTrue($breaching['is_breaching_threshold']);

        config(['fiscal.pending_alert_threshold_minutes' => 1440]);
        $notBreaching = collect(app(ComplianceService::class)->syncHealth())->firstWhere('terminal_id', $terminal->id);
        $this->assertFalse($notBreaching['is_breaching_threshold']);
    }
}
