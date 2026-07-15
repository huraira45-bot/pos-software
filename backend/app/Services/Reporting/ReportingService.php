<?php

namespace App\Services\Reporting;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\StockLevel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregate queries for the admin/reporting screens. Nothing here
 * mutates state - reports are always derived from invoices/invoice_items,
 * never a separately-maintained running total, so they can never drift from
 * the fiscal source of truth.
 *
 * Every report-suite method (everything except dayClose/reconciliation/
 * b2bInvoices, which predate the report suite and serve other screens) returns
 * one standard envelope via envelope(): {summary, columns, rows, totals, meta}.
 * This lets a single frontend ReportView render all of them.
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

    /** Sales Summary report: sales vs returns vs net, for any date range (no terminal required). */
    public function salesSummary(array $filters): array
    {
        $base = Invoice::query()
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('sold_at', '<=', $v));

        $sales = (clone $base)->where('invoice_type', Invoice::TYPE_NEW);
        $credits = (clone $base)->where('invoice_type', Invoice::TYPE_CREDIT);

        $salesCount = (clone $sales)->count();
        $salesValue = (string) (clone $sales)->sum('total_sale_value');
        $salesTax = (string) (clone $sales)->sum('total_tax_charged');
        $salesBill = (string) (clone $sales)->sum('total_bill_amount');
        $creditsCount = (clone $credits)->count();
        $creditsBill = (string) (clone $credits)->sum('total_bill_amount');
        $net = bcsub($salesBill ?: '0', $creditsBill ?: '0', 2);

        return $this->envelope(
            summary: [
                $this->metric('invoice_count', 'Invoices', (string) $salesCount, 'number'),
                $this->metric('total_sale_value', 'Sale Value', $salesValue, 'currency'),
                $this->metric('total_tax_charged', 'Tax Charged', $salesTax, 'currency'),
                $this->metric('net_bill_amount', 'Net Revenue', $net, 'currency'),
            ],
            columns: [
                ['key' => 'label', 'label' => '', 'align' => 'left'],
                ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'number'],
                ['key' => 'total_bill_amount', 'label' => 'Bill Amount', 'align' => 'right', 'format' => 'currency'],
            ],
            rows: [
                ['label' => 'Sales', 'invoice_count' => (string) $salesCount, 'total_bill_amount' => $salesBill],
                ['label' => 'Returns', 'invoice_count' => (string) $creditsCount, 'total_bill_amount' => '-' . $creditsBill],
                ['label' => 'Net', 'invoice_count' => (string) ($salesCount - $creditsCount), 'total_bill_amount' => (string) $net],
            ],
            totals: ['total_bill_amount' => (string) $net],
            report: 'sales_summary',
            filters: $filters,
        );
    }

    /** Backs both the Daily Sales and Monthly Sales reports, plus the dashboard trend chart. */
    public function salesTrend(array $filters, string $granularity = 'day'): array
    {
        // substr on the timestamp's own ISO-formatted text works identically on
        // Postgres and SQLite; to_char()/strftime() would each only work on one.
        // The CAST is required on Postgres - substr() has no timestamp overload there,
        // whereas SQLite's dynamic typing accepts it either way.
        $dateExpr = $granularity === 'month' ? 'substr(CAST(sold_at AS TEXT), 1, 7)' : 'substr(CAST(sold_at AS TEXT), 1, 10)';

        $rows = DB::table('invoices')
            ->where('invoice_type', Invoice::TYPE_NEW)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('sold_at', '<=', $v))
            ->selectRaw("$dateExpr as period, count(*) as invoice_count, sum(total_bill_amount) as total_revenue")
            ->groupByRaw($dateExpr)
            ->orderBy('period')
            ->get();

        $totalRevenue = $this->money($rows->sum('total_revenue'));
        $totalInvoices = (string) $rows->sum('invoice_count');

        return $this->envelope(
            summary: [
                $this->metric('invoice_count', 'Invoices', $totalInvoices, 'number'),
                $this->metric('total_revenue', 'Total Revenue', $totalRevenue, 'currency'),
            ],
            columns: [
                ['key' => 'period', 'label' => $granularity === 'month' ? 'Month' : 'Date', 'align' => 'left'],
                ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'number'],
                ['key' => 'total_revenue', 'label' => 'Revenue', 'align' => 'right', 'format' => 'currency'],
            ],
            rows: $rows->map(fn ($r) => [
                'period' => (string) $r->period,
                'invoice_count' => (string) $r->invoice_count,
                'total_revenue' => (string) $r->total_revenue,
            ])->all(),
            totals: ['invoice_count' => $totalInvoices, 'total_revenue' => $totalRevenue],
            report: 'sales_trend',
            filters: $filters,
            extraMeta: ['granularity' => $granularity],
        );
    }

    public function salesByItem(array $filters): array
    {
        $rows = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->leftJoin('products', 'products.id', '=', 'invoice_items.product_id')
            ->where('invoices.invoice_type', Invoice::TYPE_NEW)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('invoices.branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '<=', $v))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('invoice_items.product_id', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('products.category_id', $v))
            ->selectRaw('invoice_items.item_code, invoice_items.item_name, sum(invoice_items.quantity) as qty_sold, sum(invoice_items.tax_charged) as tax_charged, sum(invoice_items.total_amount) as total_revenue')
            ->groupBy('invoice_items.item_code', 'invoice_items.item_name')
            ->orderByDesc('total_revenue')
            ->get();

        $totalRevenue = $this->money($rows->sum('total_revenue'));
        $totalQty = (string) $rows->sum('qty_sold');
        $totalTax = $this->money($rows->sum('tax_charged'));

        return $this->envelope(
            summary: [
                $this->metric('qty_sold', 'Units Sold', $totalQty, 'number'),
                $this->metric('total_revenue', 'Total Revenue', $totalRevenue, 'currency'),
            ],
            columns: [
                ['key' => 'item_code', 'label' => 'Item Code', 'align' => 'left'],
                ['key' => 'item_name', 'label' => 'Item Name', 'align' => 'left'],
                ['key' => 'qty_sold', 'label' => 'Qty Sold', 'align' => 'right', 'format' => 'number'],
                ['key' => 'tax_charged', 'label' => 'Tax', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'total_revenue', 'label' => 'Revenue', 'align' => 'right', 'format' => 'currency'],
            ],
            rows: $rows->map(fn ($r) => [
                'item_code' => $r->item_code,
                'item_name' => $r->item_name,
                'qty_sold' => (string) $r->qty_sold,
                'tax_charged' => (string) $r->tax_charged,
                'total_revenue' => (string) $r->total_revenue,
            ])->all(),
            totals: ['qty_sold' => $totalQty, 'tax_charged' => $totalTax, 'total_revenue' => $totalRevenue],
            report: 'sales_by_item',
            filters: $filters,
        );
    }

    public function salesByCategory(array $filters): array
    {
        $rows = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('invoices.invoice_type', Invoice::TYPE_NEW)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('invoices.branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '<=', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('products.category_id', $v))
            ->selectRaw("coalesce(categories.name, 'Uncategorized') as category, sum(invoice_items.quantity) as qty_sold, sum(invoice_items.total_amount) as total_revenue")
            ->groupBy('categories.name')
            ->orderByDesc('total_revenue')
            ->get();

        $totalRevenue = $this->money($rows->sum('total_revenue'));

        return $this->envelope(
            summary: [
                $this->metric('category_count', 'Categories', (string) $rows->count(), 'number'),
                $this->metric('total_revenue', 'Total Revenue', $totalRevenue, 'currency'),
            ],
            columns: [
                ['key' => 'category', 'label' => 'Category', 'align' => 'left'],
                ['key' => 'qty_sold', 'label' => 'Qty Sold', 'align' => 'right', 'format' => 'number'],
                ['key' => 'total_revenue', 'label' => 'Revenue', 'align' => 'right', 'format' => 'currency'],
            ],
            rows: $rows->map(fn ($r) => [
                'category' => $r->category,
                'qty_sold' => (string) $r->qty_sold,
                'total_revenue' => (string) $r->total_revenue,
            ])->all(),
            totals: ['total_revenue' => $totalRevenue],
            report: 'sales_by_category',
            filters: $filters,
        );
    }

    public function salesByCashier(array $filters): array
    {
        $rows = DB::table('invoices')
            ->join('users', 'users.id', '=', 'invoices.cashier_id')
            ->where('invoices.invoice_type', Invoice::TYPE_NEW)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('invoices.branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '<=', $v))
            ->when($filters['cashier_id'] ?? null, fn ($q, $v) => $q->where('invoices.cashier_id', $v))
            ->selectRaw('users.id as cashier_id, users.name as cashier_name, count(*) as invoice_count, sum(invoices.total_bill_amount) as total_revenue')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_revenue')
            ->get();

        $totalRevenue = $this->money($rows->sum('total_revenue'));

        return $this->envelope(
            summary: [
                $this->metric('cashier_count', 'Cashiers', (string) $rows->count(), 'number'),
                $this->metric('total_revenue', 'Total Revenue', $totalRevenue, 'currency'),
            ],
            columns: [
                ['key' => 'cashier_name', 'label' => 'Cashier', 'align' => 'left'],
                ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'number'],
                ['key' => 'total_revenue', 'label' => 'Revenue', 'align' => 'right', 'format' => 'currency'],
            ],
            rows: $rows->map(fn ($r) => [
                'cashier_name' => $r->cashier_name,
                'invoice_count' => (string) $r->invoice_count,
                'total_revenue' => (string) $r->total_revenue,
            ])->all(),
            totals: ['total_revenue' => $totalRevenue],
            report: 'sales_by_cashier',
            filters: $filters,
        );
    }

    /**
     * LEFT JOIN (not INNER) so walk-in sales (null customer_id) appear as their
     * own "Walk-in" row rather than being silently dropped - otherwise this
     * report's total would never reconcile with Sales Summary.
     */
    public function salesByCustomer(array $filters): array
    {
        $rows = DB::table('invoices')
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->where('invoices.invoice_type', Invoice::TYPE_NEW)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('invoices.branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '<=', $v))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('invoices.customer_id', $v))
            ->selectRaw("invoices.customer_id, coalesce(customers.name, 'Walk-in') as customer_name, coalesce(customers.customer_type, 'walk_in') as customer_type, count(*) as invoice_count, sum(invoices.total_bill_amount) as total_revenue")
            ->groupBy('invoices.customer_id', 'customers.name', 'customers.customer_type')
            ->orderByDesc('total_revenue')
            ->get();

        $totalRevenue = $this->money($rows->sum('total_revenue'));

        return $this->envelope(
            summary: [
                $this->metric('customer_count', 'Customers', (string) $rows->count(), 'number'),
                $this->metric('total_revenue', 'Total Revenue', $totalRevenue, 'currency'),
            ],
            columns: [
                ['key' => 'customer_name', 'label' => 'Customer', 'align' => 'left'],
                ['key' => 'customer_type', 'label' => 'Type', 'align' => 'left'],
                ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'number'],
                ['key' => 'total_revenue', 'label' => 'Revenue', 'align' => 'right', 'format' => 'currency'],
            ],
            rows: $rows->map(fn ($r) => [
                'customer_name' => $r->customer_name,
                'customer_type' => $r->customer_type === 'b2b' ? 'B2B' : 'Walk-in',
                'invoice_count' => (string) $r->invoice_count,
                'total_revenue' => (string) $r->total_revenue,
            ])->all(),
            totals: ['total_revenue' => $totalRevenue],
            report: 'sales_by_customer',
            filters: $filters,
        );
    }

    /** Per-customer statement: opening balance -> invoices in range -> closing balance. */
    public function b2bCustomerStatement(int $customerId, array $filters): array
    {
        $customer = Customer::findOrFail($customerId);

        $rows = Invoice::query()
            ->where('customer_id', $customerId)
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('sold_at', '<=', $v))
            ->orderBy('sold_at')
            ->get(['id', 'usin', 'invoice_type', 'total_bill_amount', 'sold_at', 'fiscal_status']);

        $openingBalance = (string) ($customer->opening_balance ?? '0.00');
        $periodTotal = '0.00';
        foreach ($rows as $r) {
            $signed = $r->invoice_type === Invoice::TYPE_CREDIT
                ? bcmul((string) $r->total_bill_amount, '-1', 2)
                : (string) $r->total_bill_amount;
            $periodTotal = bcadd($periodTotal, $signed, 2);
        }
        $closingBalance = bcadd($openingBalance, $periodTotal, 2);

        return $this->envelope(
            summary: [
                $this->metric('opening_balance', 'Opening Balance', $openingBalance, 'currency'),
                $this->metric('period_activity', 'Period Activity', (string) $periodTotal, 'currency'),
                $this->metric('closing_balance', 'Closing Balance', (string) $closingBalance, 'currency'),
            ],
            columns: [
                ['key' => 'sold_at', 'label' => 'Date', 'align' => 'left', 'format' => 'datetime'],
                ['key' => 'usin', 'label' => 'USIN', 'align' => 'left'],
                ['key' => 'invoice_type', 'label' => 'Type', 'align' => 'left'],
                ['key' => 'total_bill_amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'fiscal_status', 'label' => 'Fiscal', 'align' => 'left'],
            ],
            rows: $rows->map(fn ($r) => [
                'sold_at' => (string) $r->sold_at,
                'usin' => (string) $r->usin,
                'invoice_type' => $r->invoice_type === Invoice::TYPE_CREDIT ? 'Return' : 'Sale',
                'total_bill_amount' => (string) $r->total_bill_amount,
                'fiscal_status' => $r->fiscal_status,
            ])->all(),
            totals: ['total_bill_amount' => (string) $periodTotal],
            report: 'b2b_customer_statement',
            filters: array_merge($filters, ['customer_id' => $customerId]),
            extraMeta: ['customer_name' => $customer->company_name ?: $customer->name, 'closing_balance' => (string) $closingBalance],
        );
    }

    /**
     * "Outstanding Payments" in this system means customer account balances,
     * NOT unpaid invoices - CheckoutService requires tenders to exactly match
     * total_bill_amount, so no invoice is ever left partially paid. This report
     * is a credit-utilization view built from each B2B customer's own ledger
     * fields (opening_balance/credit_limit/payment_terms_days).
     */
    public function customerBalances(array $filters): array
    {
        $rows = Customer::query()
            ->where('customer_type', Customer::TYPE_B2B)
            ->where('is_active', true)
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('id', $v))
            ->orderByDesc('opening_balance')
            ->get(['id', 'name', 'company_name', 'opening_balance', 'credit_limit', 'payment_terms_days']);

        $totalBalance = $this->money($rows->sum('opening_balance'));
        $totalCredit = $this->money($rows->sum('credit_limit'));

        return $this->envelope(
            summary: [
                $this->metric('customer_count', 'B2B Customers', (string) $rows->count(), 'number'),
                $this->metric('total_balance', 'Total Account Balance', $totalBalance, 'currency'),
                $this->metric('total_credit_limit', 'Total Credit Limit', $totalCredit, 'currency'),
            ],
            columns: [
                ['key' => 'name', 'label' => 'Customer', 'align' => 'left'],
                ['key' => 'payment_terms_days', 'label' => 'Terms (days)', 'align' => 'right', 'format' => 'number'],
                ['key' => 'credit_limit', 'label' => 'Credit Limit', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'opening_balance', 'label' => 'Account Balance', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'available_credit', 'label' => 'Available Credit', 'align' => 'right', 'format' => 'currency'],
            ],
            rows: $rows->map(fn ($c) => [
                'name' => $c->company_name ?: $c->name,
                'payment_terms_days' => (string) ($c->payment_terms_days ?? 0),
                'credit_limit' => (string) $c->credit_limit,
                'opening_balance' => (string) $c->opening_balance,
                'available_credit' => (string) max(0.0, (float) $c->credit_limit - (float) $c->opening_balance),
            ])->all(),
            totals: ['credit_limit' => $totalCredit, 'opening_balance' => $totalBalance],
            report: 'customer_account_balances',
            filters: $filters,
            extraMeta: ['label' => 'Customer Account Balances (Credit)', 'note' => 'This system has no unpaid-invoice tracking - every sale is paid in full at checkout. These are manually-maintained account/credit balances, not unpaid invoices.'],
        );
    }

    /** Tax collected report: matches exactly what was (or will be) posted to FBR per invoice. */
    public function taxCollected(array $filters): array
    {
        $base = Invoice::query()
            ->where('invoice_type', Invoice::TYPE_NEW)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('sold_at', '<=', $v));

        $totalTax = (string) (clone $base)->sum('total_tax_charged');
        $totalFurther = (string) (clone $base)->sum('further_tax');
        $syncedTax = (string) (clone $base)->where('fiscal_status', Invoice::FISCAL_SYNCED)->sum('total_tax_charged');
        $unsyncedTax = (string) (clone $base)->where('fiscal_status', '!=', Invoice::FISCAL_SYNCED)->sum('total_tax_charged');

        $rateRows = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.invoice_type', Invoice::TYPE_NEW)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('invoices.branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '<=', $v))
            ->selectRaw('invoice_items.tax_rate, sum(invoice_items.sale_value) as sale_value, sum(invoice_items.tax_charged) as tax_charged')
            ->groupBy('invoice_items.tax_rate')
            ->orderByDesc('invoice_items.tax_rate')
            ->get();

        return $this->envelope(
            summary: [
                $this->metric('total_tax_charged', 'Total Tax Charged', $totalTax, 'currency'),
                $this->metric('total_further_tax', 'Further Tax', $totalFurther, 'currency'),
                $this->metric('synced_tax_charged', 'Synced', $syncedTax, 'currency'),
                $this->metric('unsynced_tax_charged', 'Unsynced', $unsyncedTax, 'currency'),
            ],
            columns: [
                ['key' => 'tax_rate', 'label' => 'Tax Rate %', 'align' => 'right', 'format' => 'number'],
                ['key' => 'sale_value', 'label' => 'Taxable Value', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'tax_charged', 'label' => 'Tax Charged', 'align' => 'right', 'format' => 'currency'],
            ],
            rows: $rateRows->map(fn ($r) => [
                'tax_rate' => (string) $r->tax_rate,
                'sale_value' => (string) $r->sale_value,
                'tax_charged' => (string) $r->tax_charged,
            ])->all(),
            totals: ['sale_value' => $this->money($rateRows->sum('sale_value')), 'tax_charged' => $totalTax],
            report: 'tax_collected',
            filters: $filters,
        );
    }

    /**
     * Profit uses each product's CURRENT cost_price - there is no per-line cost
     * snapshot at time of sale, so this is necessarily an approximation for
     * past sales if cost has changed since (same limitation inventoryValuation
     * already accepts).
     */
    public function profitByItem(array $filters): array
    {
        $rows = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->leftJoin('products', 'products.id', '=', 'invoice_items.product_id')
            ->where('invoices.invoice_type', Invoice::TYPE_NEW)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('invoices.branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('invoices.sold_at', '<=', $v))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('invoice_items.product_id', $v))
            ->selectRaw('
                invoice_items.item_code,
                invoice_items.item_name,
                sum(invoice_items.quantity) as qty_sold,
                sum(invoice_items.total_amount) as revenue,
                sum(invoice_items.quantity * coalesce(products.cost_price, 0)) as cost,
                sum(case when products.cost_price is null then 1 else 0 end) as missing_cost_lines
            ')
            ->groupBy('invoice_items.item_code', 'invoice_items.item_name')
            ->orderByDesc('revenue')
            ->get();

        $totalRevenue = $this->money($rows->sum('revenue'));
        $totalCost = $this->money($rows->sum('cost'));
        $totalProfit = bcsub($totalRevenue, $totalCost, 2);
        $missingCostCount = (int) $rows->sum('missing_cost_lines');

        return $this->envelope(
            summary: [
                $this->metric('total_revenue', 'Revenue', $totalRevenue, 'currency'),
                $this->metric('total_cost', 'Cost (current)', $totalCost, 'currency'),
                $this->metric('total_profit', 'Gross Profit', (string) $totalProfit, 'currency'),
            ],
            columns: [
                ['key' => 'item_code', 'label' => 'Item Code', 'align' => 'left'],
                ['key' => 'item_name', 'label' => 'Item Name', 'align' => 'left'],
                ['key' => 'qty_sold', 'label' => 'Qty', 'align' => 'right', 'format' => 'number'],
                ['key' => 'revenue', 'label' => 'Revenue', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'cost', 'label' => 'Cost', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'profit', 'label' => 'Profit', 'align' => 'right', 'format' => 'currency'],
            ],
            rows: $rows->map(fn ($r) => [
                'item_code' => $r->item_code,
                'item_name' => $r->item_name,
                'qty_sold' => (string) $r->qty_sold,
                'revenue' => (string) $r->revenue,
                'cost' => (string) $r->cost,
                'profit' => (string) bcsub((string) $r->revenue, (string) $r->cost, 2),
            ])->all(),
            totals: ['revenue' => $totalRevenue, 'cost' => $totalCost, 'profit' => (string) $totalProfit],
            report: 'profit_by_item',
            filters: $filters,
            extraMeta: [
                'caveat' => "Profit uses each product's current cost_price, not a historical cost snapshot at time of sale.",
                'missing_cost_lines' => $missingCostCount,
            ],
        );
    }

    /** Point-in-time snapshot (no date filter) - same predicate as StockController::lowStock but fit to the report envelope, with a valuation column. */
    public function inventoryValuation(array $filters): array
    {
        $rows = StockLevel::query()
            ->join('products', 'products.id', '=', 'stock_levels.product_id')
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('stock_levels.branch_id', $v))
            ->selectRaw('products.item_code, products.name, stock_levels.quantity, coalesce(products.cost_price, 0) as cost_price, (stock_levels.quantity * coalesce(products.cost_price, 0)) as line_value')
            ->orderByDesc('line_value')
            ->get();

        $totalValue = $this->money($rows->sum('line_value'));
        $totalUnits = (string) $rows->sum('quantity');

        return $this->envelope(
            summary: [
                $this->metric('total_units', 'Total Units', $totalUnits, 'number'),
                $this->metric('total_value', 'Inventory Value', $totalValue, 'currency'),
            ],
            columns: [
                ['key' => 'item_code', 'label' => 'Item Code', 'align' => 'left'],
                ['key' => 'name', 'label' => 'Product', 'align' => 'left'],
                ['key' => 'quantity', 'label' => 'Qty', 'align' => 'right', 'format' => 'number'],
                ['key' => 'cost_price', 'label' => 'Cost Price', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'line_value', 'label' => 'Value', 'align' => 'right', 'format' => 'currency'],
            ],
            rows: $rows->map(fn ($r) => [
                'item_code' => $r->item_code,
                'name' => $r->name,
                'quantity' => (string) $r->quantity,
                'cost_price' => (string) $r->cost_price,
                'line_value' => (string) $r->line_value,
            ])->all(),
            totals: ['quantity' => $totalUnits, 'line_value' => $totalValue],
            report: 'inventory_valuation',
            filters: $filters,
        );
    }

    /** Same predicate as StockController::lowStock, fit to the report envelope. */
    public function lowStock(array $filters): array
    {
        $rows = StockLevel::query()
            ->join('products', 'products.id', '=', 'stock_levels.product_id')
            ->where('products.track_stock', true)
            ->whereColumn('stock_levels.quantity', '<=', 'products.reorder_level')
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('stock_levels.branch_id', $v))
            ->selectRaw('products.item_code, products.name, stock_levels.quantity, products.reorder_level, (products.reorder_level - stock_levels.quantity) as shortfall')
            ->orderByDesc('shortfall')
            ->get();

        return $this->envelope(
            summary: [
                $this->metric('low_stock_count', 'Low-Stock Items', (string) $rows->count(), 'number'),
            ],
            columns: [
                ['key' => 'item_code', 'label' => 'Item Code', 'align' => 'left'],
                ['key' => 'name', 'label' => 'Product', 'align' => 'left'],
                ['key' => 'quantity', 'label' => 'On Hand', 'align' => 'right', 'format' => 'number'],
                ['key' => 'reorder_level', 'label' => 'Reorder Level', 'align' => 'right', 'format' => 'number'],
                ['key' => 'shortfall', 'label' => 'Shortfall', 'align' => 'right', 'format' => 'number'],
            ],
            rows: $rows->map(fn ($r) => [
                'item_code' => $r->item_code,
                'name' => $r->name,
                'quantity' => (string) $r->quantity,
                'reorder_level' => (string) $r->reorder_level,
                'shortfall' => (string) $r->shortfall,
            ])->all(),
            totals: [],
            report: 'low_stock',
            filters: $filters,
        );
    }

    /**
     * Generalized from dayClose's payment_breakdown grouping, but range-based
     * rather than one-day. Mixed-tender invoices (payment_mode=Mixed) have
     * their real tenders recovered from the payment_breakdown JSON column and
     * attributed back to the actual methods used, rather than being lumped
     * into one opaque "Mixed" bucket.
     */
    public function salesByPaymentMethod(array $filters): array
    {
        $base = Invoice::query()
            ->where('invoice_type', Invoice::TYPE_NEW)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('sold_at', '<=', $v));

        $simple = (clone $base)->where('payment_mode', '!=', Invoice::PAYMENT_MIXED)
            ->selectRaw('payment_mode, count(*) as count, sum(total_bill_amount) as total')
            ->groupBy('payment_mode')
            ->get()
            ->keyBy('payment_mode');

        $mixedInvoices = (clone $base)->where('payment_mode', Invoice::PAYMENT_MIXED)->get(['payment_breakdown']);

        $labels = [
            Invoice::PAYMENT_CASH => 'Cash',
            Invoice::PAYMENT_CARD => 'Card',
            Invoice::PAYMENT_GIFT_VOUCHER => 'Gift Voucher',
            Invoice::PAYMENT_LOYALTY_CARD => 'Loyalty Card',
            Invoice::PAYMENT_CHEQUE => 'Cheque',
        ];

        $totals = [];
        foreach ($labels as $mode => $label) {
            $totals[$mode] = [
                'mode' => $mode,
                'label' => $label,
                'count' => (int) ($simple[$mode]->count ?? 0),
                'total' => (float) ($simple[$mode]->total ?? 0),
            ];
        }

        foreach ($mixedInvoices as $invoice) {
            foreach ((array) ($invoice->payment_breakdown ?? []) as $tender) {
                $mode = (int) ($tender['mode'] ?? 0);
                $amount = (float) ($tender['amount'] ?? 0);
                if (! isset($totals[$mode])) {
                    $totals[$mode] = ['mode' => $mode, 'label' => $labels[$mode] ?? "Mode {$mode}", 'count' => 0, 'total' => 0.0];
                }
                $totals[$mode]['total'] += $amount;
                $totals[$mode]['count']++;
            }
        }

        $rows = collect($totals)->filter(fn ($r) => $r['count'] > 0)->sortByDesc('total')->values();
        $grandTotal = $rows->sum('total');

        return $this->envelope(
            summary: [
                $this->metric('grand_total', 'Total Collected', number_format($grandTotal, 2, '.', ''), 'currency'),
            ],
            columns: [
                ['key' => 'label', 'label' => 'Method', 'align' => 'left'],
                ['key' => 'count', 'label' => 'Tenders', 'align' => 'right', 'format' => 'number'],
                ['key' => 'total', 'label' => 'Amount', 'align' => 'right', 'format' => 'currency'],
            ],
            rows: $rows->map(fn ($r) => [
                'label' => $r['label'],
                'count' => (string) $r['count'],
                'total' => number_format($r['total'], 2, '.', ''),
            ])->all(),
            totals: ['total' => number_format($grandTotal, 2, '.', '')],
            report: 'sales_by_payment_method',
            filters: $filters,
            extraMeta: ['note' => "Mixed-tender sales are split back into their real payment methods using each sale's recorded tender breakdown."],
        );
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

    /** B2B invoices with buyer NTNs - useful for the buyer's input-tax claims and our audit trail. */
    public function b2bInvoices(array $filters): \Illuminate\Support\Collection
    {
        return Invoice::query()
            ->where('invoice_type', Invoice::TYPE_NEW)
            ->whereNotNull('buyer_ntn')
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('sold_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('sold_at', '<=', $v))
            ->with('customer:id,name,customer_type')
            ->orderByDesc('sold_at')
            ->get([
                'id', 'usin', 'terminal_id', 'customer_id', 'buyer_ntn', 'buyer_name',
                'total_sale_value', 'total_tax_charged', 'further_tax', 'total_bill_amount',
                'fiscal_status', 'sold_at',
            ]);
    }

    private function metric(string $key, string $label, string $value, string $format = 'currency'): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'format' => $format];
    }

    /**
     * Collection::sum() (as opposed to the query builder's ->sum() aggregate,
     * which runs SQL SUM() and returns the driver's own decimal-formatted
     * string) re-sums already-fetched PHP values and returns a plain float -
     * casting that straight to string drops trailing zeros ("1000" instead of
     * "1000.00") whenever the total happens to land on a whole number. Every
     * currency total built from an in-memory Collection sum must go through
     * this to stay formatted consistently.
     */
    private function money(float|int|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $summary
     * @param  array<int, array<string, mixed>>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $extraMeta
     */
    private function envelope(array $summary, array $columns, iterable $rows, array $totals, string $report, array $filters, array $extraMeta = []): array
    {
        return [
            'summary' => $summary,
            'columns' => $columns,
            'rows' => is_array($rows) ? array_values($rows) : iterator_to_array($rows),
            'totals' => $totals,
            'meta' => array_merge([
                'report' => $report,
                'filters' => $filters,
                'generated_at' => now()->toIso8601String(),
            ], $extraMeta),
        ];
    }
}
