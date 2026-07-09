<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Customers\AtlImportService;
use App\Support\PosPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class AtlImportController extends Controller
{
    public function __construct(private readonly AtlImportService $atlImportService)
    {
    }

    public function status(Request $request)
    {
        $this->authorizeManage($request);

        return response()->json([
            'last_refreshed_at' => Customer::whereNotNull('atl_checked_at')->max('atl_checked_at'),
            'counts' => [
                'active' => Customer::where('atl_status', Customer::ATL_ACTIVE)->count(),
                'inactive' => Customer::where('atl_status', Customer::ATL_INACTIVE)->count(),
                'unknown' => Customer::where('atl_status', Customer::ATL_UNKNOWN)->count(),
            ],
        ]);
    }

    public function import(Request $request)
    {
        $this->authorizeManage($request);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
        ]);

        $result = $this->atlImportService->importFromCsv($request->file('file')->getRealPath());

        return response()->json($result, empty($result['errors']) ? 200 : 422);
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can(PosPermissions::CUSTOMER_MANAGE)) {
            throw new AuthorizationException();
        }
    }
}
