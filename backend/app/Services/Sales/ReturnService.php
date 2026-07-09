<?php

namespace App\Services\Sales;

use App\Exceptions\Sales\InvalidReturnException;
use App\Exceptions\Sales\PaymentMismatchException;
use App\Jobs\FiscalizeInvoiceJob;
use App\Models\AuditLog;
use App\Models\FiscalOutbox;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Fiscal\Support\Money;
use App\Services\Fiscal\UsinGenerator;
use App\Services\Inventory\StockService;
use App\Support\PosPermissions;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a Credit invoice (FBR InvoiceType=3) against an original New sale,
 * supporting full or item-level partial returns. The original invoice is never
 * touched - fiscal documents are immutable here; a return is always a new
 * document that references the old one via RefUSIN, exactly like every other
 * correction in this system.
 *
 * Credit amounts are recomputed from the *original line's per-unit* price/tax/
 * discount rather than as a ratio of that line's already-rounded totals, so
 * partial returns don't compound rounding error across repeated partial returns
 * of the same line.
 */
class ReturnService
{
    public function __construct(
        private readonly UsinGenerator $usinGenerator,
        private readonly StockService $stockService,
    ) {
    }

    /**
     * @param array{
     *   original_invoice_id:int, terminal_id:int,
     *   lines: list<array{invoice_item_id:int, quantity:string}>,
     *   tenders: list<array{mode:int, amount:string}>,
     * } $input
     */
    public function createReturn(array $input, User $actor): Invoice
    {
        if (! $actor->can(PosPermissions::RETURNS_CREATE)) {
            throw new AuthorizationException('Creating returns requires additional permission.');
        }

        $original = Invoice::with('items.product')->findOrFail($input['original_invoice_id']);

        if ($original->isCredit()) {
            throw new InvalidReturnException('Cannot return a credit invoice.');
        }

        if (empty($input['lines'])) {
            throw new InvalidReturnException('At least one return line is required.');
        }

        $terminal = Terminal::findOrFail($input['terminal_id']);

        $creditLines = [];
        $totalSaleValue = Money::zero();
        $totalTaxCharged = Money::zero();
        $totalDiscount = Money::zero();
        $totalFurtherTax = Money::zero();
        $totalAmount = Money::zero();

        foreach ($input['lines'] as $line) {
            /** @var InvoiceItem|null $originalItem */
            $originalItem = $original->items->firstWhere('id', $line['invoice_item_id']);
            if (! $originalItem) {
                throw new InvalidReturnException("Invoice item {$line['invoice_item_id']} does not belong to invoice {$original->id}.");
            }

            $returnQty = (string) $line['quantity'];
            if (bccomp($returnQty, '0', 3) <= 0) {
                throw new InvalidReturnException("Return quantity for item {$originalItem->item_code} must be positive.");
            }

            $alreadyReturned = InvoiceItem::query()
                ->where('ref_invoice_item_id', $originalItem->id)
                ->sum('quantity');
            $remaining = bcsub((string) $originalItem->quantity, (string) $alreadyReturned, 3);

            if (bccomp($returnQty, $remaining, 3) > 0) {
                throw new InvalidReturnException(
                    "Cannot return {$returnQty} of {$originalItem->item_code}; only {$remaining} remain returnable."
                );
            }

            // Recompute from the original line's per-unit economics, not as a
            // ratio of its (already rounded) totals - keeps repeated partial
            // returns of the same line from compounding rounding drift.
            $originalQuantity = BigDecimal::of((string) $originalItem->quantity);
            $unitPrice = Money::of($originalItem->unit_price_excl_tax);
            $unitDiscount = $originalQuantity->isZero()
                ? Money::zero()
                : Money::of($originalItem->discount)->dividedBy($originalQuantity, 6, RoundingMode::HALF_UP);
            $unitFurtherTax = $originalQuantity->isZero()
                ? Money::zero()
                : Money::of($originalItem->further_tax)->dividedBy($originalQuantity, 6, RoundingMode::HALF_UP);

            $saleValue = $unitPrice->multipliedBy($returnQty)->toScale(2, RoundingMode::HALF_UP);
            $discount = $unitDiscount->multipliedBy($returnQty)->toScale(2, RoundingMode::HALF_UP);
            $taxableValue = $saleValue->minus($discount);
            $taxCharged = $taxableValue->multipliedBy(Money::of($originalItem->tax_rate))
                ->dividedBy(100, 2, RoundingMode::HALF_UP);
            $furtherTax = $unitFurtherTax->multipliedBy($returnQty)->toScale(2, RoundingMode::HALF_UP);
            $lineTotal = $taxableValue->plus($taxCharged)->plus($furtherTax);

            $creditLines[] = [
                'ref_invoice_item_id' => $originalItem->id,
                'product_id' => $originalItem->product_id,
                'product_variant_id' => $originalItem->product_variant_id,
                'item_code' => $originalItem->item_code,
                'item_name' => $originalItem->item_name,
                'pct_code' => $originalItem->pct_code,
                'quantity' => $returnQty,
                'unit_price_excl_tax' => Money::toDecimalString($unitPrice),
                'tax_rate' => (string) $originalItem->tax_rate,
                'sale_value' => Money::toDecimalString($saleValue),
                'discount' => Money::toDecimalString($discount),
                'tax_charged' => Money::toDecimalString($taxCharged),
                'further_tax' => Money::toDecimalString($furtherTax),
                'total_amount' => Money::toDecimalString($lineTotal),
                'branch_id' => $original->branch_id,
                'track_stock' => $originalItem->product?->track_stock ?? false,
            ];

            $totalSaleValue = $totalSaleValue->plus($saleValue);
            $totalTaxCharged = $totalTaxCharged->plus($taxCharged);
            $totalDiscount = $totalDiscount->plus($discount);
            $totalFurtherTax = $totalFurtherTax->plus($furtherTax);
            $totalAmount = $totalAmount->plus($lineTotal);
        }

        $tenders = $input['tenders'] ?? [];
        if (empty($tenders)) {
            throw new InvalidReturnException('At least one refund tender is required.');
        }
        $sumTenders = array_reduce($tenders, fn ($carry, $t) => bcadd($carry, (string) $t['amount'], 2), '0.00');
        $totalAmountStr = Money::toDecimalString($totalAmount);
        if (bccomp($sumTenders, $totalAmountStr, 2) !== 0) {
            throw new PaymentMismatchException($totalAmountStr, $sumTenders);
        }
        $paymentMode = count($tenders) > 1 ? Invoice::PAYMENT_MIXED : (int) $tenders[0]['mode'];

        [$credit, $outboxId] = DB::transaction(function () use (
            $original, $terminal, $creditLines, $totalSaleValue, $totalTaxCharged,
            $totalDiscount, $totalFurtherTax, $totalAmount, $tenders, $paymentMode, $actor,
        ) {
            $usin = $this->usinGenerator->next($terminal->id);

            $credit = Invoice::create([
                'branch_id' => $original->branch_id,
                'terminal_id' => $terminal->id,
                'usin' => $usin,
                'invoice_type' => Invoice::TYPE_CREDIT,
                'ref_invoice_id' => $original->id,
                'buyer_ntn' => $original->buyer_ntn,
                'buyer_cnic' => $original->buyer_cnic,
                'buyer_name' => $original->buyer_name,
                'buyer_phone' => $original->buyer_phone,
                'total_sale_value' => Money::toDecimalString($totalSaleValue),
                'total_tax_charged' => Money::toDecimalString($totalTaxCharged),
                'discount' => Money::toDecimalString($totalDiscount),
                'further_tax' => Money::toDecimalString($totalFurtherTax),
                'total_bill_amount' => Money::toDecimalString($totalAmount),
                'payment_mode' => $paymentMode,
                'payment_breakdown' => count($tenders) > 1 ? $tenders : null,
                'fiscal_status' => Invoice::FISCAL_PENDING,
                'printed_offline_pending' => true,
                'sold_at' => now(),
                'cashier_id' => $actor->id,
            ]);

            foreach ($creditLines as $line) {
                $credit->items()->create([
                    'product_id' => $line['product_id'],
                    'product_variant_id' => $line['product_variant_id'],
                    'ref_invoice_item_id' => $line['ref_invoice_item_id'],
                    'item_code' => $line['item_code'],
                    'item_name' => $line['item_name'],
                    'pct_code' => $line['pct_code'],
                    'quantity' => $line['quantity'],
                    'unit_price_excl_tax' => $line['unit_price_excl_tax'],
                    'tax_rate' => $line['tax_rate'],
                    'sale_value' => $line['sale_value'],
                    'discount' => $line['discount'],
                    'tax_charged' => $line['tax_charged'],
                    'further_tax' => $line['further_tax'],
                    'total_amount' => $line['total_amount'],
                    'invoice_type' => Invoice::TYPE_CREDIT,
                ]);

                if ($line['track_stock']) {
                    $this->stockService->adjust(
                        $line['branch_id'],
                        $line['product_id'],
                        $line['product_variant_id'],
                        $line['quantity'],
                    );
                }
            }

            $outbox = FiscalOutbox::create([
                'invoice_id' => $credit->id,
                'idempotency_key' => "{$terminal->id}:{$usin}",
                'status' => FiscalOutbox::STATUS_PENDING,
                'next_attempt_at' => now(),
            ]);

            AuditLog::record('invoice.credit_created', $credit, $original->toArray(), $credit->fresh(['items'])->toArray());

            return [$credit, $outbox->id];
        });

        FiscalizeInvoiceJob::dispatch($outboxId);

        return $credit->fresh(['items', 'terminal', 'branch', 'refInvoice']);
    }
}
