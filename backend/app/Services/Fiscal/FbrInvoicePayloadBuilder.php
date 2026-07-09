<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\InvoiceTotalsMismatchException;
use App\Models\Invoice;
use App\Services\Fiscal\Support\Money;
use Brick\Math\BigDecimal;

/**
 * Builds the FBR POS "PostData" JSON body from an Invoice + its items, per the
 * Tier-1 IMS integration data model. Shared by every cloud/local adapter since
 * they all speak the same request contract - only the endpoint and transport
 * differ per adapter.
 */
class FbrInvoicePayloadBuilder
{
    public function build(Invoice $invoice): array
    {
        $invoice->loadMissing(['items', 'terminal', 'refInvoice']);

        $this->assertTotalsReconcile($invoice);

        return [
            'InvoiceNumber' => '',
            'POSID' => $invoice->terminal->fbr_pos_id,
            'USIN' => (string) $invoice->usin,
            'DateTime' => $invoice->sold_at->toIso8601String(),
            'BuyerNTN' => $invoice->buyer_ntn ?? '',
            'BuyerCNIC' => $invoice->buyer_cnic ?? '',
            'BuyerName' => $invoice->buyer_name ?? '',
            'BuyerPhoneNumber' => $invoice->buyer_phone ?? '',
            'TotalSaleValue' => Money::toApiDouble($invoice->total_sale_value),
            'TotalTaxCharged' => Money::toApiDouble($invoice->total_tax_charged),
            'Discount' => Money::toApiDouble($invoice->discount),
            'FurtherTax' => Money::toApiDouble($invoice->further_tax),
            'TotalBillAmount' => Money::toApiDouble($invoice->total_bill_amount),
            'PaymentMode' => $invoice->payment_mode,
            'InvoiceType' => $invoice->invoice_type,
            'RefUSIN' => $invoice->refInvoice ? (string) $invoice->refInvoice->usin : '',
            'Items' => $invoice->items->map(fn ($item) => [
                'ItemCode' => $item->item_code,
                'ItemName' => $item->item_name,
                'Quantity' => (float) $item->quantity,
                'PCTCode' => $item->pct_code,
                'TaxRate' => (float) $item->tax_rate,
                'SaleValue' => Money::toApiDouble($item->sale_value),
                'TaxCharged' => Money::toApiDouble($item->tax_charged),
                'TotalAmount' => Money::toApiDouble($item->total_amount),
                'Discount' => Money::toApiDouble($item->discount),
                'FurtherTax' => Money::toApiDouble($item->further_tax),
                'InvoiceType' => $item->invoice_type,
            ])->all(),
        ];
    }

    /**
     * Defense in depth: even though CheckoutService/ReturnService validate totals
     * at creation time, we re-verify right before every FBR submission (including
     * retries of older invoices) so a payload can never be sent out of balance.
     */
    private function assertTotalsReconcile(Invoice $invoice): void
    {
        $sumSaleValue = BigDecimal::zero();
        $sumTax = BigDecimal::zero();
        $sumDiscount = BigDecimal::zero();
        $sumFurtherTax = BigDecimal::zero();
        $sumTotal = BigDecimal::zero();

        foreach ($invoice->items as $item) {
            $sumSaleValue = $sumSaleValue->plus(Money::of($item->sale_value));
            $sumTax = $sumTax->plus(Money::of($item->tax_charged));
            $sumDiscount = $sumDiscount->plus(Money::of($item->discount));
            $sumFurtherTax = $sumFurtherTax->plus(Money::of($item->further_tax));
            $sumTotal = $sumTotal->plus(Money::of($item->total_amount));
        }

        $checks = [
            'TotalSaleValue' => [$sumSaleValue, Money::of($invoice->total_sale_value)],
            'TotalTaxCharged' => [$sumTax, Money::of($invoice->total_tax_charged)],
            'Discount' => [$sumDiscount, Money::of($invoice->discount)],
            'FurtherTax' => [$sumFurtherTax, Money::of($invoice->further_tax)],
            'TotalBillAmount' => [$sumTotal, Money::of($invoice->total_bill_amount)],
        ];

        foreach ($checks as $field => [$computed, $stored]) {
            if (! $computed->isEqualTo($stored)) {
                throw new InvoiceTotalsMismatchException($invoice->id, $field, $computed->__toString(), $stored->__toString());
            }
        }
    }
}
