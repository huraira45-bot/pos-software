<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Receipt\ReceiptDataBuilder;
use App\Services\Receipt\ReceiptPdfService;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    public function __construct(
        private readonly ReceiptDataBuilder $dataBuilder,
        private readonly ReceiptPdfService $pdfService,
    ) {
    }

    /** Consumed by the local print-agent to drive the ESC/POS thermal printer. */
    public function show(Invoice $invoice)
    {
        return response()->json($this->dataBuilder->build($invoice));
    }

    /** PDF fallback for email/WhatsApp sharing, or reprint after FBR sync completes. */
    public function pdf(Invoice $invoice, Request $request)
    {
        $paperSize = $request->query('paper', '80mm') === 'a4' ? 'a4' : '80mm';

        $pdf = $this->pdfService->render($invoice, $paperSize);

        return $pdf->stream("invoice-{$invoice->usin}.pdf");
    }
}
