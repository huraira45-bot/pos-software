<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reporting\ReportingService;
use App\Support\PosPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly ReportingService $reports)
    {
    }

    public function dayClose(Request $request)
    {
        $this->authorizeReports($request);

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'terminal_id' => ['required', 'integer', 'exists:terminals,id'],
            'date' => ['required', 'date'],
        ]);

        return response()->json($this->reports->dayClose($data['branch_id'], $data['terminal_id'], $data['date']));
    }

    public function salesByItem(Request $request)
    {
        $this->authorizeReports($request);

        return response()->json($this->reports->salesByItem($request->only(['branch_id', 'from', 'to'])));
    }

    public function salesByCategory(Request $request)
    {
        $this->authorizeReports($request);

        return response()->json($this->reports->salesByCategory($request->only(['branch_id', 'from', 'to'])));
    }

    public function salesByCashier(Request $request)
    {
        $this->authorizeReports($request);

        return response()->json($this->reports->salesByCashier($request->only(['branch_id', 'from', 'to'])));
    }

    public function taxCollected(Request $request)
    {
        $this->authorizeReports($request);

        return response()->json($this->reports->taxCollected($request->only(['branch_id', 'from', 'to'])));
    }

    public function reconciliation(Request $request)
    {
        $this->authorizeReports($request);

        return response()->json($this->reports->reconciliation($request->only(['branch_id', 'from', 'to'])));
    }

    public function inventoryValuation(Request $request)
    {
        $this->authorizeReports($request);

        return response()->json($this->reports->inventoryValuation($request->integer('branch_id') ?: null));
    }

    public function salesByCustomer(Request $request)
    {
        $this->authorizeReports($request);

        return response()->json($this->reports->salesByCustomer($request->only(['branch_id', 'from', 'to'])));
    }

    public function b2bInvoices(Request $request)
    {
        $this->authorizeReports($request);

        return response()->json($this->reports->b2bInvoices($request->only(['branch_id', 'from', 'to'])));
    }

    private function authorizeReports(Request $request): void
    {
        if (! $request->user()->can(PosPermissions::REPORTS_VIEW)) {
            throw new AuthorizationException();
        }
    }
}
