<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Terminal;
use App\Support\PosPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TerminalController extends Controller
{
    public function index(Request $request)
    {
        $terminals = Terminal::query()
            ->when($request->integer('branch_id'), fn ($q, $v) => $q->where('branch_id', $v))
            ->get()
            ->map(fn (Terminal $t) => $this->present($t));

        return response()->json($terminals);
    }

    public function store(Request $request)
    {
        $this->authorizeManage($request);

        $data = $this->validated($request);
        // refresh() so the response reflects the DB default for is_active when
        // the request omits it - Terminal::create()'s in-memory instance only
        // has what was explicitly assigned, not what Postgres actually applied.
        $terminal = Terminal::create($data)->refresh();

        return response()->json($this->present($terminal), 201);
    }

    public function show(Terminal $terminal)
    {
        return response()->json($this->present($terminal));
    }

    public function update(Request $request, Terminal $terminal)
    {
        $this->authorizeManage($request);

        $data = $this->validated($request, $terminal);
        $previousMode = $terminal->effectiveFiscalMode();

        $terminal->update($data);

        // Switching a terminal to the live production endpoint is one of the
        // highest-consequence config changes in this system - always audited,
        // regardless of who made it or from which screen.
        if (isset($data['fiscal_mode']) && $data['fiscal_mode'] !== $previousMode) {
            AuditLog::record('terminal.fiscal_mode_changed', $terminal, ['fiscal_mode' => $previousMode], ['fiscal_mode' => $data['fiscal_mode']]);
        }

        return response()->json($this->present($terminal));
    }

    private function validated(Request $request, ?Terminal $terminal = null): array
    {
        return $request->validate([
            'branch_id' => [$terminal ? 'sometimes' : 'required', 'integer', 'exists:branches,id'],
            'code' => [$terminal ? 'sometimes' : 'required', 'string', 'max:50'],
            'name' => [$terminal ? 'sometimes' : 'required', 'string', 'max:255'],
            'fbr_pos_id' => [
                $terminal ? 'sometimes' : 'required', 'integer',
                Rule::unique('terminals', 'fbr_pos_id')->ignore($terminal?->id),
            ],
            'fiscal_mode' => ['nullable', Rule::in(['fbr_cloud', 'fbr_sandbox', 'pra_cloud', 'pra_sandbox', 'local_sdc', 'mock'])],
            'fiscal_endpoint_override' => ['nullable', 'string', 'max:255'],
            'fiscal_token' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** fiscal_token is write-only - never echoed back, even to admins. */
    private function present(Terminal $terminal): array
    {
        return [
            'id' => $terminal->id,
            'branch_id' => $terminal->branch_id,
            'code' => $terminal->code,
            'name' => $terminal->name,
            'fbr_pos_id' => $terminal->fbr_pos_id,
            'fiscal_mode' => $terminal->effectiveFiscalMode(),
            'fiscal_mode_override' => $terminal->fiscal_mode,
            'fiscal_endpoint_override' => $terminal->fiscal_endpoint_override,
            'has_fiscal_token' => $terminal->fiscal_token !== null,
            'is_active' => (bool) $terminal->is_active,
            'last_seen_at' => $terminal->last_seen_at?->toIso8601String(),
        ];
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can(PosPermissions::TERMINAL_MANAGE)) {
            throw new AuthorizationException();
        }
    }
}
