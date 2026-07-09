<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseRequest;
use App\Models\Purchase;
use App\Services\Inventory\PurchaseService;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function __construct(private readonly PurchaseService $purchaseService)
    {
    }

    public function index(Request $request)
    {
        $purchases = Purchase::with(['items', 'supplier'])
            ->when($request->integer('branch_id'), fn ($q, $v) => $q->where('branch_id', $v))
            ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($purchases);
    }

    public function store(StorePurchaseRequest $request)
    {
        $purchase = $this->purchaseService->createDraft($request->validated(), $request->user());

        return response()->json($purchase, 201);
    }

    public function show(Purchase $purchase)
    {
        return response()->json($purchase->load(['items', 'supplier', 'creator']));
    }

    public function receive(Purchase $purchase)
    {
        $purchase = $this->purchaseService->receive($purchase);

        return response()->json($purchase);
    }
}
