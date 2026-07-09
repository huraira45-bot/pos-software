<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StockAdjustment;
use App\Models\StockLevel;
use App\Services\Inventory\StockService;
use App\Support\PosPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    public function __construct(private readonly StockService $stockService)
    {
    }

    public function levels(Request $request)
    {
        $levels = StockLevel::with(['product', 'variant'])
            ->when($request->integer('branch_id'), fn ($q, $v) => $q->where('branch_id', $v))
            ->paginate($request->integer('per_page', 50));

        return response()->json($levels);
    }

    /** Products whose stock has fallen to or below their configured reorder level. */
    public function lowStock(Request $request)
    {
        $branchId = $request->integer('branch_id');

        $levels = StockLevel::query()
            ->with('product', 'variant', 'branch')
            ->join('products', 'products.id', '=', 'stock_levels.product_id')
            ->whereColumn('stock_levels.quantity', '<=', 'products.reorder_level')
            ->where('products.track_stock', true)
            ->when($branchId, fn ($q) => $q->where('stock_levels.branch_id', $branchId))
            ->select('stock_levels.*')
            ->get();

        return response()->json($levels);
    }

    public function adjust(Request $request)
    {
        if (! $request->user()->can(PosPermissions::STOCK_ADJUST)) {
            throw new AuthorizationException();
        }

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $adjustment = DB::transaction(function () use ($data, $request) {
            $this->stockService->adjust(
                $data['branch_id'],
                $data['product_id'],
                $data['product_variant_id'] ?? null,
                (string) $data['quantity_delta'],
            );

            $record = StockAdjustment::create([
                ...$data,
                'user_id' => $request->user()->id,
            ]);

            AuditLog::record('stock.adjusted', $record, null, $record->toArray());

            return $record;
        });

        return response()->json($adjustment, 201);
    }
}
