<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\PosPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        if (! $request->user()->can(PosPermissions::COMPLIANCE_DASHBOARD)) {
            throw new AuthorizationException();
        }

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->string('action') . '%'))
            ->when($request->integer('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->filled('auditable_type'), fn ($q) => $q->where('auditable_type', $request->string('auditable_type')))
            ->when($request->date('from'), fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->date('to'), fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 50));

        return response()->json($logs);
    }
}
