<?php

namespace App\Services\Receipt;

use App\Models\Invoice;

/**
 * Builds one plain-array receipt model from an Invoice, shared by:
 *  - ReceiptController's JSON endpoint, consumed by the local Node print-agent
 *    (which renders the QR image + FBR logo itself and drives the ESC/POS printer)
 *  - the dompdf Blade view for the PDF fallback (email/WhatsApp sharing)
 *
 * Keeping one builder means the thermal receipt and the PDF can never drift on
 * what SRO 1006(I)/2021 requires them both to show.
 */
class ReceiptDataBuilder
{
    public function build(Invoice $invoice): array
    {
        $invoice->loadMissing(['items', 'branch', 'terminal', 'refInvoice', 'cashier']);

        $isSynced = $invoice->fiscal_status === Invoice::FISCAL_SYNCED;

        return [
            'business' => [
                'name' => config('pos.business_name'),
                'branch_name' => $invoice->branch->name,
                'address' => $invoice->branch->fullAddress(),
                'ntn' => $invoice->branch->ntn,
                'strn' => $invoice->branch->strn,
                'tax_office_name' => $invoice->branch->tax_office_name,
                'pos_registration_number' => $invoice->terminal->fbr_pos_id,
            ],
            'invoice' => [
                'usin' => (string) $invoice->usin,
                'invoice_type' => $invoice->invoice_type,
                'is_credit' => $invoice->isCredit(),
                'ref_usin' => $invoice->refUsinValue(),
                'date_time' => $invoice->sold_at->toIso8601String(),
                'date_time_display' => $invoice->sold_at->format('d-M-Y H:i'),
                'payment_mode' => $invoice->payment_mode,
                'payment_mode_label' => $this->paymentModeLabel($invoice->payment_mode),
                'payment_breakdown' => $invoice->payment_breakdown,
                'buyer_name' => $invoice->buyer_name,
                'buyer_ntn' => $invoice->buyer_ntn,
                'buyer_cnic' => $invoice->buyer_cnic,
                'cashier_name' => $invoice->cashier?->name,
            ],
            'items' => $invoice->items->map(fn ($item) => [
                'name' => $item->item_name,
                'quantity' => (string) $item->quantity,
                'unit_price_excl_tax' => (string) $item->unit_price_excl_tax,
                'tax_rate' => (string) $item->tax_rate,
                'tax_charged' => (string) $item->tax_charged,
                'discount' => (string) $item->discount,
                'total_amount' => (string) $item->total_amount,
            ])->all(),
            'totals' => [
                'total_sale_value' => (string) $invoice->total_sale_value,
                'total_tax_charged' => (string) $invoice->total_tax_charged,
                'discount' => (string) $invoice->discount,
                'further_tax' => (string) $invoice->further_tax,
                'total_bill_amount' => (string) $invoice->total_bill_amount,
            ],
            'fiscal' => [
                'status' => $invoice->fiscal_status,
                'is_pending' => ! $isSynced,
                'pending_annotation' => $isSynced ? null : 'FBR sync pending',
                'fbr_invoice_number' => $invoice->fbr_invoice_number,
                // Only ever set once FBR has actually returned a fiscal number -
                // there is nothing valid to encode before that.
                'qr_payload' => $isSynced ? $invoice->fbr_invoice_number : null,
            ],
            'footer' => [
                'fbr_statement' => config('receipt.fbr_footer_statement'),
                'fbr_logo_path' => config('receipt.fbr_logo_path'),
                'qr_size_mm' => config('receipt.qr_size_mm'),
                'currency_symbol' => config('receipt.currency_symbol'),
            ],
        ];
    }

    private function paymentModeLabel(int $mode): string
    {
        return match ($mode) {
            Invoice::PAYMENT_CASH => 'Cash',
            Invoice::PAYMENT_CARD => 'Card',
            Invoice::PAYMENT_GIFT_VOUCHER => 'Gift Voucher',
            Invoice::PAYMENT_LOYALTY_CARD => 'Loyalty Card',
            Invoice::PAYMENT_MIXED => 'Mixed',
            Invoice::PAYMENT_CHEQUE => 'Cheque',
            default => 'Unknown',
        };
    }
}
