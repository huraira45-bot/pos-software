<?php

namespace App\Services\Inventory;

use App\Models\StockLevel;
use Illuminate\Support\Facades\DB;

/**
 * All stock mutations go through here so every write is a locked read-modify-write
 * inside the caller's transaction (never a blind increment that could race).
 * Negative stock is allowed by default (POS sales must never block on inventory
 * accuracy - a Tier-1 retailer would rather oversell and reconcile later than stall
 * checkout), but every movement is still recorded precisely.
 */
class StockService
{
    /**
     * Raw locked read-modify-write. Callers that are already inside a DB
     * transaction (e.g. CheckoutService building an invoice) should call this
     * directly so the stock lock is held by that same transaction. The
     * decrementForSale/incrementForReturn/incrementForPurchase wrappers below
     * open their own transaction for standalone callers.
     */
    public function adjust(int $branchId, int $productId, ?int $productVariantId, string $delta): StockLevel
    {
        $level = StockLevel::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where(function ($q) use ($productVariantId) {
                $productVariantId === null
                    ? $q->whereNull('product_variant_id')
                    : $q->where('product_variant_id', $productVariantId);
            })
            ->lockForUpdate()
            ->first();

        if (! $level) {
            $level = StockLevel::create([
                'branch_id' => $branchId,
                'product_id' => $productId,
                'product_variant_id' => $productVariantId,
                'quantity' => 0,
            ]);
            $level = StockLevel::query()->whereKey($level->id)->lockForUpdate()->first();
        }

        $level->update(['quantity' => bcadd((string) $level->quantity, $delta, 3)]);

        return $level->refresh();
    }

    public function decrementForSale(int $branchId, int $productId, ?int $productVariantId, string $quantity): void
    {
        DB::transaction(fn () => $this->adjust($branchId, $productId, $productVariantId, bcmul($quantity, '-1', 3)));
    }

    public function incrementForReturn(int $branchId, int $productId, ?int $productVariantId, string $quantity): void
    {
        DB::transaction(fn () => $this->adjust($branchId, $productId, $productVariantId, $quantity));
    }

    public function incrementForPurchase(int $branchId, int $productId, ?int $productVariantId, string $quantity): void
    {
        DB::transaction(fn () => $this->adjust($branchId, $productId, $productVariantId, $quantity));
    }
}
