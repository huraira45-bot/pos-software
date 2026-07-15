<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Terminal;
use App\Models\UsinCounter;
use App\Services\Fiscal\UsinGenerator;
use App\Support\PosPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Lets ops view/set each terminal's SIR/SS USIN counters - e.g. "the next SIR
 * invoice on this till should be SIR-1056". Setting last_value directly (as
 * opposed to only ever incrementing via UsinGenerator::next()) is deliberately
 * gated behind pos.terminal-manage and only ever done outside a sale
 * transaction, since it's a one-off provisioning action, not part of the
 * gapless allocation path itself.
 */
class UsinCounterController extends Controller
{
    public function index(Request $request, Terminal $terminal)
    {
        $this->authorizeManage($request);

        $counters = UsinCounter::query()
            ->where('terminal_id', $terminal->id)
            ->get(['usin_type', 'last_value'])
            ->map(fn (UsinCounter $c) => [
                'usin_type' => $c->usin_type,
                'last_value' => $c->last_value,
                'next_usin' => $c->usin_type . UsinGenerator::SEPARATORS[$c->usin_type] . ($c->last_value + 1),
            ]);

        return response()->json($counters);
    }

    public function update(Request $request, Terminal $terminal, string $usinType)
    {
        $this->authorizeManage($request);

        if (! isset(UsinGenerator::SEPARATORS[$usinType])) {
            throw ValidationException::withMessages(['usin_type' => 'Must be one of: ' . implode(', ', array_keys(UsinGenerator::SEPARATORS))]);
        }

        $data = $request->validate([
            // The NEXT number this terminal/type should issue, e.g. 1056 for USIN SIR-1056.
            'start_from' => ['required', 'integer', 'min:1'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $counter = UsinCounter::query()
            ->where('terminal_id', $terminal->id)
            ->where('usin_type', $usinType)
            ->firstOrFail();

        $newLastValue = $data['start_from'] - 1;

        if ($newLastValue < $counter->last_value && empty($data['force'])) {
            throw ValidationException::withMessages([
                'start_from' => "This terminal has already issued {$usinType} numbers up to {$counter->last_value}. "
                    . "Setting the next number to {$data['start_from']} would eventually collide with already-issued invoices. "
                    . 'Pass force=true to override if you understand the risk.',
            ]);
        }

        $counter->update(['last_value' => $newLastValue]);

        return response()->json([
            'usin_type' => $usinType,
            'last_value' => $counter->last_value,
            'next_usin' => $usinType . UsinGenerator::SEPARATORS[$usinType] . ($counter->last_value + 1),
        ]);
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can(PosPermissions::TERMINAL_MANAGE)) {
            throw new AuthorizationException();
        }
    }
}
