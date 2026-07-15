<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportFilterRequest;
use App\Http\Resources\ReportResource;
use App\Services\Reporting\ReportingService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Every action here is gated by the `can:pos.reports-view` route middleware
 * (see routes/api.php) rather than an in-controller check, so authorization
 * can never be forgotten on a new endpoint added to this controller.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportingService $reports)
    {
    }

    /** Kept as its own raw endpoint (not the report envelope) - a single-day, single-terminal shift-close screen. */
    public function dayClose(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'terminal_id' => ['required', 'integer', 'exists:terminals,id'],
            'date' => ['required', 'date'],
        ]);

        return response()->json($this->reports->dayClose($data['branch_id'], $data['terminal_id'], $data['date']));
    }

    public function salesSummary(ReportFilterRequest $request)
    {
        return ReportResource::make($this->reports->salesSummary($request->validated()));
    }

    public function salesTrend(ReportFilterRequest $request)
    {
        $filters = $request->validated();

        return ReportResource::make($this->reports->salesTrend($filters, $filters['granularity'] ?? 'day'));
    }

    public function salesByItem(ReportFilterRequest $request)
    {
        return ReportResource::make($this->reports->salesByItem($request->validated()));
    }

    public function salesByCategory(ReportFilterRequest $request)
    {
        return ReportResource::make($this->reports->salesByCategory($request->validated()));
    }

    public function salesByCashier(ReportFilterRequest $request)
    {
        return ReportResource::make($this->reports->salesByCashier($request->validated()));
    }

    public function salesByCustomer(ReportFilterRequest $request)
    {
        return ReportResource::make($this->reports->salesByCustomer($request->validated()));
    }

    public function b2bStatement(ReportFilterRequest $request)
    {
        $filters = $request->validated();

        if (empty($filters['customer_id'])) {
            throw ValidationException::withMessages(['customer_id' => 'customer_id is required for the B2B statement.']);
        }

        return ReportResource::make($this->reports->b2bCustomerStatement((int) $filters['customer_id'], $filters));
    }

    public function customerBalances(ReportFilterRequest $request)
    {
        return ReportResource::make($this->reports->customerBalances($request->validated()));
    }

    public function taxCollected(ReportFilterRequest $request)
    {
        return ReportResource::make($this->reports->taxCollected($request->validated()));
    }

    public function profit(ReportFilterRequest $request)
    {
        return ReportResource::make($this->reports->profitByItem($request->validated()));
    }

    public function inventoryValuation(ReportFilterRequest $request)
    {
        return ReportResource::make($this->reports->inventoryValuation($request->validated()));
    }

    public function lowStock(ReportFilterRequest $request)
    {
        return ReportResource::make($this->reports->lowStock($request->validated()));
    }

    public function paymentMethods(ReportFilterRequest $request)
    {
        return ReportResource::make($this->reports->salesByPaymentMethod($request->validated()));
    }

    /** Kept as a raw list (not the report envelope) - feeds the compliance gap-list use case, not the ReportsPage registry. */
    public function reconciliation(ReportFilterRequest $request)
    {
        return response()->json($this->reports->reconciliation($request->validated()));
    }

    /** Kept as a raw list (not the report envelope) - a flat NTN-invoice list distinct from the B2B statement report. */
    public function b2bInvoices(ReportFilterRequest $request)
    {
        return response()->json($this->reports->b2bInvoices($request->validated()));
    }
}
