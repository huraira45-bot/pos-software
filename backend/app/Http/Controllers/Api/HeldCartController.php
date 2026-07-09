<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HeldCart;
use Illuminate\Http\Request;

/**
 * "Hold" lets a cashier park an in-progress cart (e.g. customer forgot their
 * wallet) and serve the next customer; "recall" brings it back into the
 * checkout screen. Nothing here touches invoices/USIN/fiscalization - a held
 * cart is pre-sale, not yet a finalized transaction.
 */
class HeldCartController extends Controller
{
    public function index(Request $request)
    {
        $carts = HeldCart::query()
            ->where('terminal_id', $request->integer('terminal_id'))
            ->whereNull('recalled_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($carts);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'terminal_id' => ['required', 'integer', 'exists:terminals,id'],
            'label' => ['nullable', 'string', 'max:255'],
            'cart_data' => ['required', 'array'],
        ]);

        $cart = HeldCart::create([
            ...$data,
            'cashier_id' => $request->user()->id,
        ]);

        return response()->json($cart, 201);
    }

    public function recall(HeldCart $heldCart)
    {
        if ($heldCart->recalled_at) {
            return response()->json(['message' => 'This cart was already recalled.'], 409);
        }

        $heldCart->update(['recalled_at' => now()]);

        return response()->json($heldCart);
    }

    public function destroy(HeldCart $heldCart)
    {
        $heldCart->delete();

        return response()->noContent();
    }
}
