<?php

namespace App\Services\Receipt;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Server-side PDF fallback receipt (80mm or A4) for email/WhatsApp sharing.
 * The physical thermal receipt at the till is rendered by the local print-agent
 * from ReceiptDataBuilder's JSON, not from this PDF.
 */
class ReceiptPdfService
{
    public function __construct(
        private readonly ReceiptDataBuilder $dataBuilder,
        private readonly QrCodeGenerator $qrCodeGenerator,
    ) {
    }

    public function render(Invoice $invoice, string $paperSize = '80mm'): \Barryvdh\DomPDF\PDF
    {
        $data = $this->dataBuilder->build($invoice);

        $qrDataUri = $data['fiscal']['qr_payload']
            ? $this->qrCodeGenerator->toBase64DataUri($data['fiscal']['qr_payload'])
            : null;

        return Pdf::loadView('receipts.receipt', [
            'data' => $data,
            'qrDataUri' => $qrDataUri,
            'logoDataUri' => $this->logoDataUri(),
            'paperSize' => $paperSize,
        ])->setPaper($paperSize === '80mm' ? [0, 0, 226.77, 841.89] : 'a4');
    }

    /** Embedded as base64 (not a file path) so dompdf never needs filesystem/chroot config to find it. */
    private function logoDataUri(): ?string
    {
        $path = public_path(config('receipt.fbr_logo_path'));

        if (! is_file($path)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode(file_get_contents($path));
    }
}
