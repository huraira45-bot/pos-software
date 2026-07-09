<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FiscalOutbox;
use App\Services\Compliance\ComplianceService;
use App\Support\PosPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class ComplianceController extends Controller
{
    public function __construct(private readonly ComplianceService $compliance)
    {
    }

    public function syncHealth(Request $request)
    {
        $this->authorizeDashboard($request);

        return response()->json($this->compliance->syncHealth());
    }

    public function failed(Request $request)
    {
        $this->authorizeDashboard($request);

        return response()->json($this->compliance->failedSubmissions($request->integer('terminal_id') ?: null));
    }

    public function retry(Request $request, FiscalOutbox $fiscalOutbox)
    {
        $this->authorizeDashboard($request);

        $this->compliance->retry($fiscalOutbox);

        return response()->json(['message' => 'Re-queued for another attempt.']);
    }

    private function authorizeDashboard(Request $request): void
    {
        if (! $request->user()->can(PosPermissions::COMPLIANCE_DASHBOARD)) {
            throw new AuthorizationException();
        }
    }
}
