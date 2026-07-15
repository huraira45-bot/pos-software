<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReturnRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Sales\ReturnService;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    public function __construct(private readonly ReturnService $returnService)
    {
    }

    /**
     * Look up the original invoice a cashier wants to return against, by USIN
     * (scoped to a terminal, since USIN alone is only unique per terminal) or by
     * the FBR-assigned fiscal invoice number. Includes each line's remaining
     * returnable quantity so the UI can block over-returning before submit.
     */
    public function lookup(Request $request)
    {
        $request->validate([
            'usin' => ['required_without:fbr_invoice_number', 'string'],
            'terminal_id' => ['required_with:usin', 'integer', 'exists:terminals,id'],
            'fbr_invoice_number' => ['required_without:usin', 'string'],
        ]);

        $query = Invoice::with('items')->where('invoice_type', Invoice::TYPE_NEW);

        if ($request->filled('fbr_invoice_number')) {
            $query->where('fbr_invoice_number', $request->string('fbr_invoice_number'));
        } else {
            $query->where('usin', $request->string('usin'))
                ->where('terminal_id', $request->integer('terminal_id'));
        }

        $invoice = $query->firstOrFail();

        $items = $invoice->items->map(function (InvoiceItem $item) {
            $alreadyReturned = InvoiceItem::query()
                ->where('ref_invoice_item_id', $item->id)
                ->sum('quantity');

            return [
                'id' => $item->id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'quantity' => (string) $item->quantity,
                'already_returned' => (string) $alreadyReturned,
                'remaining_returnable' => bcsub((string) $item->quantity, (string) $alreadyReturned, 3),
                'unit_price_excl_tax' => (string) $item->unit_price_excl_tax,
                'tax_rate' => (string) $item->tax_rate,
            ];
        });

        return response()->json([
            'invoice' => InvoiceResource::make($invoice),
            'items' => $items,
        ]);
    }

    public function store(StoreReturnRequest $request)
    {
        $credit = $this->returnService->createReturn($request->validated(), $request->user());

        return InvoiceResource::make($credit)->response()->setStatusCode(201);
    }
}
