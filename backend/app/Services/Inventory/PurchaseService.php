<?php

namespace App\Services\Inventory;

use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A purchase (GRN) is entered as a draft first - stock is only ever incremented
 * once it's marked received, so a draft can be edited/cancelled freely without
 * ever having touched stock_levels.
 */
class PurchaseService
{
    public function __construct(private readonly StockService $stockService)
    {
    }

    /**
     * @param array{
     *   branch_id:int, supplier_id:int, reference_no?:string,
     *   items: list<array{product_id:int, variant_id?:int, quantity:string, unit_cost:string}>,
     * } $data
     */
    public function createDraft(array $data, User $actor): Purchase
    {
        return DB::transaction(function () use ($data, $actor) {
            $totalCost = '0.00';
            foreach ($data['items'] as $item) {
                $totalCost = bcadd($totalCost, bcmul((string) $item['quantity'], (string) $item['unit_cost'], 2), 2);
            }

            $purchase = Purchase::create([
                'branch_id' => $data['branch_id'],
                'supplier_id' => $data['supplier_id'],
                'reference_no' => $data['reference_no'] ?? null,
                'status' => 'draft',
                'total_cost' => $totalCost,
                'created_by' => $actor->id,
            ]);

            foreach ($data['items'] as $item) {
                $lineCost = bcmul((string) $item['quantity'], (string) $item['unit_cost'], 2);
                $purchase->items()->create([
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['variant_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $lineCost,
                ]);
            }

            return $purchase->load('items');
        });
    }

    public function receive(Purchase $purchase): Purchase
    {
        if ($purchase->status === 'received') {
            throw new RuntimeException('This purchase has already been received.');
        }

        return DB::transaction(function () use ($purchase) {
            foreach ($purchase->items as $item) {
                $this->stockService->adjust(
                    $purchase->branch_id,
                    $item->product_id,
                    $item->product_variant_id,
                    (string) $item->quantity,
                );
            }

            $purchase->update(['status' => 'received', 'received_at' => now()]);

            return $purchase->fresh('items');
        });
    }
}
