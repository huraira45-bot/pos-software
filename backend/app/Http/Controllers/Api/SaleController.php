<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\Sales\CheckoutService;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function __construct(private readonly CheckoutService $checkoutService)
    {
    }

    public function index(Request $request)
    {
        $invoices = Invoice::query()
            ->with(['items'])
            ->where('invoice_type', Invoice::TYPE_NEW)
            ->when($request->integer('branch_id'), fn ($q, $v) => $q->where('branch_id', $v))
            ->when($request->integer('terminal_id'), fn ($q, $v) => $q->where('terminal_id', $v))
            ->when($request->integer('customer_id'), fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->date('from'), fn ($q, $v) => $q->where('sold_at', '>=', $v->startOfDay()))
            ->when($request->date('to'), fn ($q, $v) => $q->where('sold_at', '<=', $v->endOfDay()))
            ->orderByDesc('sold_at')
            ->paginate($request->integer('per_page', 25));

        return InvoiceResource::collection($invoices);
    }

    public function store(StoreSaleRequest $request)
    {
        $invoice = $this->checkoutService->checkout($request->validated(), $request->user());

        return InvoiceResource::make($invoice)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Invoice $invoice)
    {
        return InvoiceResource::make($invoice->load(['items', 'terminal', 'branch', 'fiscalOutbox']));
    }
}
