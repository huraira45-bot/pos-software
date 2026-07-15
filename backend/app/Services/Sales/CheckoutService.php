<?php

namespace App\Services\Sales;

use App\Exceptions\Sales\BuyerInfoRequiredException;
use App\Exceptions\Sales\InvalidCartException;
use App\Exceptions\Sales\PaymentMismatchException;
use App\Jobs\FiscalizeInvoiceJob;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\FiscalOutbox;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\Fiscal\UsinGenerator;
use App\Services\Inventory\StockService;
use App\Support\PosPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates one finalized sale end-to-end: validate cart -> compute exact
 * totals -> allocate USIN -> persist invoice/items/stock/outbox in one
 * transaction -> dispatch the FBR submission job only after that transaction
 * commits. Checkout itself never calls FBR - that's the whole point of the
 * async outbox: a sale completes and prints locally regardless of FBR
 * reachability, and fiscalization catches up in the background.
 */
class CheckoutService
{
    public function __construct(
        private readonly SaleTotalsCalculator $calculator,
        private readonly UsinGenerator $usinGenerator,
        private readonly StockService $stockService,
    ) {
    }

    /**
     * @param array{
     *   branch_id:int, terminal_id:int,
     *   items: list<array{product_id:int, variant_id?:int, quantity:int|string, unit_price_excl_tax?:string, line_discount?:string, further_tax?:string}>,
     *   bill_discount?: string,
     *   tenders: list<array{mode:int, amount:string}>,
     *   buyer?: array{ntn?:string, cnic?:string, name?:string, phone?:string},
     *   customer_id?: int,
     * } $cart
     */
    public function checkout(array $cart, User $actor): Invoice
    {
        if (empty($cart['items'])) {
            throw new InvalidCartException('Cart has no items.');
        }

        $customer = $this->resolveCustomer($cart);

        $productIds = collect($cart['items'])->pluck('product_id')->unique();
        $products = Product::with('variants')->whereIn('id', $productIds)->get()->keyBy('id');

        $lines = [];
        $lineMeta = [];
        $sumRawSaleValue = '0.00';

        foreach ($cart['items'] as $idx => $cartItem) {
            $product = $products->get($cartItem['product_id']);
            if (! $product) {
                throw new InvalidCartException("Unknown product_id {$cartItem['product_id']} on line {$idx}.");
            }

            $variant = null;
            if (! empty($cartItem['variant_id'])) {
                $variant = $product->variants->firstWhere('id', $cartItem['variant_id']);
                if (! $variant) {
                    throw new InvalidCartException("Unknown variant_id {$cartItem['variant_id']} for product {$product->id}.");
                }
            }

            $catalogPrice = (string) $variant?->effectivePriceExclTax() ?: (string) $product->price_excl_tax;
            $unitPrice = isset($cartItem['unit_price_excl_tax']) ? (string) $cartItem['unit_price_excl_tax'] : $catalogPrice;

            if (bccomp($unitPrice, $catalogPrice, 2) !== 0) {
                $this->authorize($actor, PosPermissions::PRICE_OVERRIDE, 'Overriding the catalog price requires additional permission.');
            }

            $quantity = (string) $cartItem['quantity'];
            $lineDiscount = (string) ($cartItem['line_discount'] ?? '0');
            $rawSaleValue = bcmul($unitPrice, $quantity, 2);
            $sumRawSaleValue = bcadd($sumRawSaleValue, $rawSaleValue, 2);

            if (bccomp($rawSaleValue, '0', 2) > 0) {
                $discountPercent = bcmul(bcdiv($lineDiscount, $rawSaleValue, 6), '100', 2);
                if (bccomp($discountPercent, (string) config('pos.discount_permission_threshold_percent'), 2) > 0) {
                    $this->authorize($actor, PosPermissions::DISCOUNT_ABOVE_THRESHOLD, 'This discount exceeds the threshold cashiers may grant unassisted.');
                }
            }

            $furtherTax = (string) ($cartItem['further_tax'] ?? '0');

            $lines[] = [
                'item_code' => $variant->sku ?? $product->item_code,
                'item_name' => $product->name . ($variant ? " ({$variant->name})" : ''),
                'pct_code' => $product->pct_code,
                'tax_rate' => (string) $product->tax_rate,
                'unit_price_excl_tax' => $unitPrice,
                'quantity' => $quantity,
                'line_discount' => $lineDiscount,
                'further_tax' => $furtherTax,
            ];
            $lineMeta[] = [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'track_stock' => $product->track_stock,
            ];
        }

        $billDiscount = (string) ($cart['bill_discount'] ?? '0');
        if (bccomp($sumRawSaleValue, '0', 2) > 0) {
            $billDiscountPercent = bcmul(bcdiv($billDiscount, $sumRawSaleValue, 6), '100', 2);
            if (bccomp($billDiscountPercent, (string) config('pos.discount_permission_threshold_percent'), 2) > 0) {
                $this->authorize($actor, PosPermissions::DISCOUNT_ABOVE_THRESHOLD, 'This bill discount exceeds the threshold cashiers may grant unassisted.');
            }
        }

        $computed = $this->calculator->calculate($lines, $billDiscount);
        $header = $computed['header'];

        $buyer = $customer ? [
            'ntn' => $customer->formattedNtn(),
            'cnic' => $customer->formattedCnic(),
            'name' => $customer->name,
            'phone' => $customer->phone,
        ] : ($cart['buyer'] ?? []);

        $this->assertBuyerCaptured($header['total_bill_amount'], $buyer);

        $tenders = $cart['tenders'] ?? [];
        if (empty($tenders)) {
            throw new InvalidCartException('At least one payment tender is required.');
        }
        $sumTenders = array_reduce($tenders, fn ($carry, $t) => bcadd($carry, (string) $t['amount'], 2), '0.00');
        if (bccomp($sumTenders, $header['total_bill_amount'], 2) !== 0) {
            throw new PaymentMismatchException($header['total_bill_amount'], $sumTenders);
        }
        $paymentMode = count($tenders) > 1 ? Invoice::PAYMENT_MIXED : (int) $tenders[0]['mode'];

        [$invoice, $outboxId] = DB::transaction(function () use (
            $cart, $computed, $header, $lines, $lineMeta, $tenders, $paymentMode, $actor,
            $customer, $buyer,
        ) {
            $usin = $this->usinGenerator->next($cart['terminal_id'], $cart['usin_type']);

            $invoice = Invoice::create([
                'branch_id' => $cart['branch_id'],
                'terminal_id' => $cart['terminal_id'],
                'customer_id' => $customer?->id,
                'usin' => $usin,
                'usin_type' => $cart['usin_type'],
                'invoice_type' => Invoice::TYPE_NEW,
                'ref_invoice_id' => null,
                'buyer_ntn' => $buyer['ntn'] ?? null,
                'buyer_cnic' => $buyer['cnic'] ?? null,
                'buyer_name' => $buyer['name'] ?? null,
                'buyer_phone' => $buyer['phone'] ?? null,
                'total_sale_value' => $header['total_sale_value'],
                'total_tax_charged' => $header['total_tax_charged'],
                'discount' => $header['discount'],
                'further_tax' => $header['further_tax'],
                'total_bill_amount' => $header['total_bill_amount'],
                'payment_mode' => $paymentMode,
                'payment_breakdown' => count($tenders) > 1 ? $tenders : null,
                'fiscal_status' => Invoice::FISCAL_PENDING,
                'printed_offline_pending' => true,
                'sold_at' => now(),
                'cashier_id' => $actor->id,
            ]);

            foreach ($computed['lines'] as $i => $lineTotals) {
                $meta = $lineMeta[$i];

                $invoice->items()->create([
                    'product_id' => $meta['product_id'],
                    'product_variant_id' => $meta['variant_id'],
                    'item_code' => $lineTotals['item_code'],
                    'item_name' => $lineTotals['item_name'],
                    'pct_code' => $lineTotals['pct_code'],
                    'quantity' => $lineTotals['quantity'],
                    'unit_price_excl_tax' => $lineTotals['unit_price_excl_tax'],
                    'tax_rate' => $lineTotals['tax_rate'],
                    'sale_value' => $lineTotals['sale_value'],
                    'tax_charged' => $lineTotals['tax_charged'],
                    'discount' => $lineTotals['discount'],
                    'further_tax' => $lineTotals['further_tax'],
                    'total_amount' => $lineTotals['total_amount'],
                    'invoice_type' => Invoice::TYPE_NEW,
                ]);

                if ($meta['track_stock']) {
                    $this->stockService->adjust(
                        $cart['branch_id'],
                        $meta['product_id'],
                        $meta['variant_id'],
                        bcmul($lineTotals['quantity'], '-1', 3),
                    );
                }
            }

            $outbox = FiscalOutbox::create([
                'invoice_id' => $invoice->id,
                'idempotency_key' => "{$cart['terminal_id']}:{$usin}",
                'status' => FiscalOutbox::STATUS_PENDING,
                'next_attempt_at' => now(),
            ]);

            AuditLog::record('invoice.created', $invoice, null, $invoice->fresh(['items'])->toArray());

            return [$invoice, $outbox->id];
        });

        // Dispatched only now that the above transaction has actually committed,
        // so a job can never reference an outbox row that doesn't durably exist.
        FiscalizeInvoiceJob::dispatch($outboxId);

        return $invoice->fresh(['items', 'terminal', 'branch', 'customer']);
    }

    private function resolveCustomer(array $cart): ?Customer
    {
        if (empty($cart['customer_id'])) {
            return null;
        }

        return Customer::findOrFail($cart['customer_id']);
    }

    private function assertBuyerCaptured(string $totalBillAmount, array $buyer): void
    {
        if (bccomp($totalBillAmount, (string) Invoice::BUYER_CAPTURE_THRESHOLD, 2) <= 0) {
            return;
        }

        if (empty($buyer['name']) || (empty($buyer['ntn']) && empty($buyer['cnic']))) {
            throw new BuyerInfoRequiredException();
        }
    }

    private function authorize(User $actor, string $permission, string $message): void
    {
        if (! $actor->can($permission)) {
            throw new AuthorizationException($message);
        }
    }
}
