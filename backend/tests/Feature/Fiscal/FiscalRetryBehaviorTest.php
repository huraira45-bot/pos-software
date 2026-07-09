<?php

namespace Tests\Feature\Fiscal;

use App\Jobs\FiscalizeInvoiceJob;
use App\Models\FiscalOutbox;
use App\Models\Invoice;
use App\Services\Fiscal\FiscalSubmissionService;
use App\Services\Sales\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesPosTestData;
use Tests\TestCase;

/**
 * Exercises FiscalSubmissionService's retry/backoff decisions directly (not
 * through the queue) by pointing a terminal at the fbr_sandbox adapter and
 * faking its HTTP responses - this covers the exact same code path a real
 * worker uses without depending on Laravel's queue redelivery timing.
 *
 * Queue::fake() is used so checkout's own post-commit dispatch of
 * FiscalizeInvoiceJob never actually runs (it would otherwise consume the
 * first faked HTTP response before the test gets a chance to control timing) -
 * every submission in these tests is an explicit, test-driven call.
 */
class FiscalRetryBehaviorTest extends TestCase
{
    use CreatesPosTestData, RefreshDatabase;

    private function createPendingSale(): Invoice
    {
        $this->seedRolesAndPermissions();
        $branch = $this->makeBranch();
        $terminal = $this->makeTerminal($branch, ['fiscal_mode' => 'fbr_sandbox']);
        $product = $this->makeProduct(['price_excl_tax' => '100.00', 'tax_rate' => '0.00']);
        $cashier = $this->makeUser('cashier', $branch);

        Queue::fake([FiscalizeInvoiceJob::class]);

        return app(CheckoutService::class)->checkout([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'tenders' => [['mode' => Invoice::PAYMENT_CASH, 'amount' => '100.00']],
        ], $cashier);
    }

    public function test_server_error_is_retryable_and_schedules_backoff(): void
    {
        Http::fake(['ims.pral.com.pk/*' => Http::response(['message' => 'internal error'], 500)]);

        $invoice = $this->createPendingSale();
        $outbox = FiscalOutbox::where('invoice_id', $invoice->id)->firstOrFail();

        $outcome = app(FiscalSubmissionService::class)->submit($outbox->id);

        $this->assertTrue($outcome->shouldRetry());
        $this->assertSame(15, $outcome->retryDelaySeconds); // base_delay * 2^(1-1)

        $outbox->refresh();
        $this->assertSame(FiscalOutbox::STATUS_PENDING, $outbox->status);
        $this->assertSame(1, $outbox->attempts);
        $this->assertNotNull($outbox->next_attempt_at);
        $this->assertSame(Invoice::FISCAL_PENDING, $invoice->fresh()->fiscal_status);
    }

    public function test_backoff_delay_doubles_each_attempt(): void
    {
        Http::fake(['ims.pral.com.pk/*' => Http::response(['message' => 'internal error'], 500)]);

        $invoice = $this->createPendingSale();
        $outbox = FiscalOutbox::where('invoice_id', $invoice->id)->firstOrFail();
        $service = app(FiscalSubmissionService::class);

        $delays = [];
        for ($i = 0; $i < 4; $i++) {
            $outcome = $service->submit($outbox->id);
            $delays[] = $outcome->retryDelaySeconds;
        }

        $this->assertSame([15, 30, 60, 120], $delays);
    }

    public function test_non_retryable_client_error_fails_fast_without_exhausting_retries(): void
    {
        Http::fake(['ims.pral.com.pk/*' => Http::response(['Response' => 'Invalid POS ID', 'Code' => '400'], 400)]);

        $invoice = $this->createPendingSale();
        $outbox = FiscalOutbox::where('invoice_id', $invoice->id)->firstOrFail();

        $outcome = app(FiscalSubmissionService::class)->submit($outbox->id);

        $this->assertSame('failed_permanent', $outcome->status);
        $this->assertSame(FiscalOutbox::STATUS_FAILED_PERMANENT, $outbox->fresh()->status);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $this->assertSame(Invoice::FISCAL_FAILED_PERMANENT, $invoice->fresh()->fiscal_status);
    }

    public function test_exhausting_max_retry_attempts_marks_failed_permanent(): void
    {
        config(['fiscal.max_retry_attempts' => 3]);
        Http::fake(['ims.pral.com.pk/*' => Http::response(['message' => 'internal error'], 500)]);

        $invoice = $this->createPendingSale();
        $outbox = FiscalOutbox::where('invoice_id', $invoice->id)->firstOrFail();
        $service = app(FiscalSubmissionService::class);

        $outcomes = [];
        for ($i = 0; $i < 3; $i++) {
            $outcomes[] = $service->submit($outbox->id)->status;
        }

        $this->assertSame(['retry', 'retry', 'failed_permanent'], $outcomes);
        $this->assertSame(FiscalOutbox::STATUS_FAILED_PERMANENT, $outbox->fresh()->status);
    }

    public function test_successful_retry_after_prior_failure_marks_synced_and_stops(): void
    {
        Http::fake(['ims.pral.com.pk/*' => Http::sequence()
            ->push(['message' => 'internal error'], 500)
            ->push(['InvoiceNumber' => '050001-080726120000-0001', 'Response' => 'Invoice received successfully', 'Code' => '100'], 200)]);

        $invoice = $this->createPendingSale();
        $outbox = FiscalOutbox::where('invoice_id', $invoice->id)->firstOrFail();
        $service = app(FiscalSubmissionService::class);

        $first = $service->submit($outbox->id);
        $this->assertTrue($first->shouldRetry());

        $second = $service->submit($outbox->id);
        $this->assertSame('synced', $second->status);

        $this->assertSame(FiscalOutbox::STATUS_SUCCESS, $outbox->fresh()->status);
        $this->assertSame(Invoice::FISCAL_SYNCED, $invoice->fresh()->fiscal_status);
        $this->assertSame('050001-080726120000-0001', $invoice->fresh()->fbr_invoice_number);
    }

    public function test_already_synced_outbox_is_never_resubmitted(): void
    {
        Http::fake(['ims.pral.com.pk/*' => Http::response(
            ['InvoiceNumber' => '050001-080726120000-0001', 'Response' => 'Invoice received successfully', 'Code' => '100'],
            200,
        )]);

        $invoice = $this->createPendingSale();
        $outbox = FiscalOutbox::where('invoice_id', $invoice->id)->firstOrFail();
        $service = app(FiscalSubmissionService::class);

        $service->submit($outbox->id);
        $this->assertSame(FiscalOutbox::STATUS_SUCCESS, $outbox->fresh()->status);

        $outcome = $service->submit($outbox->id);
        $this->assertSame('already_synced', $outcome->status);

        Http::assertSentCount(1); // second submit() call made no network request at all
    }

    public function test_header_item_totals_mismatch_fails_permanently_without_calling_fbr(): void
    {
        Http::fake(['ims.pral.com.pk/*' => Http::response(['message' => 'should never be called'], 500)]);

        $invoice = $this->createPendingSale();
        $outbox = FiscalOutbox::where('invoice_id', $invoice->id)->firstOrFail();

        // Simulate data corruption (e.g. a bug elsewhere) producing a header
        // total that no longer reconciles with the sum of its line items.
        $invoice->forceFill(['total_bill_amount' => '999999.00'])->saveQuietly();

        $outcome = app(FiscalSubmissionService::class)->submit($outbox->id);

        $this->assertSame('failed_permanent', $outcome->status);
        $this->assertFalse($outcome->shouldRetry());
        $this->assertSame(FiscalOutbox::STATUS_FAILED_PERMANENT, $outbox->fresh()->status);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $this->assertStringContainsString('totals mismatch', $outbox->fresh()->last_error);
        $this->assertSame(Invoice::FISCAL_FAILED_PERMANENT, $invoice->fresh()->fiscal_status);

        Http::assertNothingSent();
    }
}
