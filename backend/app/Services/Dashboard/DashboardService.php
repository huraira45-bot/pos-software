<?php

namespace App\Services\Dashboard;

use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\Compliance\ComplianceService;
use App\Services\Reporting\ReportingService;
use Illuminate\Support\Carbon;

/**
 * Composes existing ReportingService/ComplianceService methods into one
 * payload so the Dashboard page fires a single request instead of 6+ parallel
 * ones. Adds no new aggregate logic of its own beyond "today" vs "range"
 * framing and picking top-N/latest-N slices.
 */
class DashboardService
{
    public function __construct(
        private readonly ReportingService $reports,
        private readonly ComplianceService $compliance,
    ) {
    }

    public function summary(array $filters): array
    {
        $branchId = $filters['branch_id'] ?? null;
        $today = Carbon::today();

        $todayFilters = array_filter(['branch_id' => $branchId, 'from' => $today->toDateString(), 'to' => $today->copy()->endOfDay()->toDateTimeString()]);
        $rangeFilters = array_filter([
            'branch_id' => $branchId,
            'from' => $filters['from'] ?? $today->copy()->subDays(29)->toDateString(),
            'to' => $filters['to'] ?? $today->copy()->endOfDay()->toDateTimeString(),
        ]);

        $todaySummary = $this->reports->salesSummary($todayFilters);
        $rangeSummary = $this->reports->salesSummary($rangeFilters);
        $balances = $this->reports->customerBalances(array_filter(['branch_id' => $branchId]));
        $lowStock = $this->reports->lowStock(array_filter(['branch_id' => $branchId]));
        $topProducts = $this->reports->salesByItem($rangeFilters);
        $trend = $this->reports->salesTrend($rangeFilters, 'day');
        $paymentMethods = $this->reports->salesByPaymentMethod($rangeFilters);

        $recentSales = Invoice::query()
            ->when($branchId, fn ($q, $v) => $q->where('branch_id', $v))
            ->where('invoice_type', Invoice::TYPE_NEW)
            ->with('items')
            ->orderByDesc('sold_at')
            ->limit(8)
            ->get();

        return [
            'today' => [
                'invoice_count' => $this->summaryMetric($todaySummary, 'invoice_count'),
                'net_bill_amount' => $this->summaryMetric($todaySummary, 'net_bill_amount'),
            ],
            'range' => [
                'from' => $rangeFilters['from'],
                'to' => $rangeFilters['to'],
                'invoice_count' => $this->summaryMetric($rangeSummary, 'invoice_count'),
                'total_revenue' => $this->summaryMetric($rangeSummary, 'net_bill_amount'),
            ],
            'pending_payments' => [
                'customer_balance_total' => $balances['summary'][1]['value'] ?? '0.00',
            ],
            'low_stock_count' => count($lowStock['rows']),
            'top_products' => array_slice($topProducts['rows'], 0, 5),
            'recent_sales' => InvoiceResource::collection($recentSales)->resolve(),
            'sales_trend' => $trend['rows'],
            'payment_breakdown' => $paymentMethods['rows'],
            'fiscal_health' => $this->compliance->syncHealth(),
        ];
    }

    private function summaryMetric(array $envelope, string $key): string
    {
        foreach ($envelope['summary'] as $metric) {
            if ($metric['key'] === $key) {
                return $metric['value'];
            }
        }

        return '0.00';
    }
}
