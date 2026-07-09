<?php

namespace App\Services\Reporting;

use App\Models\Invoice;
use App\Models\StockLevel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregate queries for the admin/reporting screens. Nothing here
 * mutates state - reports are always derived from invoices/invoice_items,
 * never a separately-maintained running total, so they can never drift from
 * the fiscal source of truth.
 */
class ReportingService
{
    /**
     * Z-report / day-close: everything a shift-end reconciliation needs for one
     * branch+terminal+day. New sales and credits are reported separately since
     * netting them together would obscure the return rate.
     */
    public function dayClose(int $branchId, int $terminalId, string $date): array
    {
        $day = Carbon::parse($date)->startOfDay();

        $base = Invoice::query()
            ->where('branch_id', $branchId)
            ->where('terminal_id', $terminalId)
            ->whereBetween('sold_at', [$day, $day->copy()->endOfDay()]);

        $sales = (clone $base)->where('invoice_type', Invoice::TYPE_NEW);
        $credits = (clone $base)->where('invoice_type', Invoice::TYPE_CREDIT);

        $paymentBreakdown = (clone $sales)
            ->selectRaw('payment_mode, count(*) as count, sum(total_bill_amount) as total')
            ->groupBy('payment_mode')
            ->get();

        return [
            'date' => $day->toDateString(),
            'branch_id' => $branchId,
            'terminal_id' => $terminalId,
            'sales' => [
                'invoice_count' => (clone $sales)->count(),
                'total_sale_value' => (string) (clone $sales)->sum('total_sale_value'),
                'total_tax_charged' => (string) (clone $sales)->sum('total_tax_charged'),
                'total_discount' => (string) (clone $sales)->sum('discount'),
                'total_bill_amount' => (string) (clone $sales)->sum('total_bill_amount'),
            ],
            'returns' => [
                'invoice_count' => (clone $credits)->count(),
                'total_bill_amount' => (string) (clone $credits)->sum('total_bill_amount'),
            ],
            'net_bill_amount' => (string) bcsub(
                (clone $sales)->sum('total_bill_amount') ?: '0',
                (clone $credits)->sum('total_bill_amount') ?: '0',
                2,
            ),
            'payment_breakdown' => $paymentBreakdown,
            'fiscal_sync' => [
                'synced' => (clone $base)->where('fiscal_status', Invoice::FISCAL_SYNCED)->count(),
                'pending' => (clone $base)->where('fiscal_status', Invoice::FISCAL_PENDING)->count(),
                'failed' => (clone $base)->where('fiscal_status', Invoice::FISCAL_FAILED_PERMANENT)->count(),
            ],
        ];
    }

    public function salesByItem(array $filters): \Illuminate\Support\Collection
    {
        return DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.invoice_type', Invoice::TYPE_NEW)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('invoices.branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '<=', $v))
            ->selectRaw('invoice_items.item_code, invoice_items.item_name, sum(invoice_items.quantity) as qty_sold, sum(invoice_items.total_amount) as total_revenue')
            ->groupBy('invoice_items.item_code', 'invoice_items.item_name')
            ->orderByDesc('total_revenue')
            ->get();
    }

    public function salesByCategory(array $filters): \Illuminate\Support\Collection
    {
        return DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('invoices.invoice_type', Invoice::TYPE_NEW)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('invoices.branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '<=', $v))
            ->selectRaw("coalesce(categories.name, 'Uncategorized') as category, sum(invoice_items.total_amount) as total_revenue")
            ->groupBy('categories.name')
            ->orderByDesc('total_revenue')
            ->get();
    }

    public function salesByCashier(array $filters): \Illuminate\Support\Collection
    {
        return DB::table('invoices')
            ->join('users', 'users.id', '=', 'invoices.cashier_id')
            ->where('invoices.invoice_type', Invoice::TYPE_NEW)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('invoices.branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '<=', $v))
            ->selectRaw('users.id as cashier_id, users.name as cashier_name, count(*) as invoice_count, sum(invoices.total_bill_amount) as total_revenue')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_revenue')
            ->get();
    }

    /** Tax collected report: matches exactly what was (or will be) posted to FBR per invoice. */
    public function taxCollected(array $filters): array
    {
        $base = Invoice::query()
            ->where('invoice_type', Invoice::TYPE_NEW)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('sold_at', '<=', $v));

        return [
            'total_tax_charged' => (string) (clone $base)->sum('total_tax_charged'),
            'total_further_tax' => (string) (clone $base)->sum('further_tax'),
            'synced_tax_charged' => (string) (clone $base)->where('fiscal_status', Invoice::FISCAL_SYNCED)->sum('total_tax_charged'),
            'unsynced_tax_charged' => (string) (clone $base)->where('fiscal_status', '!=', Invoice::FISCAL_SYNCED)->sum('total_tax_charged'),
        ];
    }

    /**
     * Reconciliation: our invoices vs FBR-confirmed. Any invoice without a
     * fiscal_status of synced is a gap; the goal is this list being empty.
     */
    public function reconciliation(array $filters): \Illuminate\Support\Collection
    {
        return Invoice::query()
            ->where('fiscal_status', '!=', Invoice::FISCAL_SYNCED)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('sold_at', '<=', $v))
            ->with('terminal:id,code,fbr_pos_id')
            ->orderBy('sold_at')
            ->get(['id', 'terminal_id', 'usin', 'fiscal_status', 'total_bill_amount', 'sold_at']);
    }

    public function inventoryValuation(?int $branchId = null): \Illuminate\Support\Collection
    {
        return StockLevel::query()
            ->join('products', 'products.id', '=', 'stock_levels.product_id')
            ->when($branchId, fn ($q, $v) => $q->where('stock_levels.branch_id', $v))
            ->selectRaw('stock_levels.branch_id, sum(stock_levels.quantity * coalesce(products.cost_price, 0)) as total_value, sum(stock_levels.quantity) as total_units')
            ->groupBy('stock_levels.branch_id')
            ->get();
    }

    public function salesByCustomer(array $filters): \Illuminate\Support\Collection
    {
        return DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->where('invoices.invoice_type', Invoice::TYPE_NEW)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('invoices.branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '<=', $v))
            ->selectRaw('customers.id as customer_id, customers.name as customer_name, customers.customer_type, count(*) as invoice_count, sum(invoices.total_bill_amount) as total_revenue')
            ->groupBy('customers.id', 'customers.name', 'customers.customer_type')
            ->orderByDesc('total_revenue')
            ->get();
    }

    /** B2B invoices with buyer NTNs - useful for the buyer's input-tax claims and our audit trail. */
    public function b2bInvoices(array $filters): \Illuminate\Support\Collection
    {
        return Invoice::query()
            ->where('invoice_type', Invoice::TYPE_NEW)
            ->whereNotNull('buyer_ntn')
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('sold_at', '<=', $v))
            ->with('customer:id,name,customer_type,atl_status')
            ->orderByDesc('sold_at')
            ->get([
                'id', 'usin', 'terminal_id', 'customer_id', 'buyer_ntn', 'buyer_name',
                'total_sale_value', 'total_tax_charged', 'further_tax', 'total_bill_amount',
                'further_tax_waived', 'fiscal_status', 'sold_at',
            ]);
    }
}
