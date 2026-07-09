<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Support\PosPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            Supplier::when($request->boolean('active_only', true), fn ($q) => $q->where('is_active', true))
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        if (! $request->user()->can(PosPermissions::PURCHASE_MANAGE)) {
            throw new AuthorizationException();
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ntn' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
        ]);

        return response()->json(Supplier::create($data), 201);
    }

    public function update(Request $request, Supplier $supplier)
    {
        if (! $request->user()->can(PosPermissions::PURCHASE_MANAGE)) {
            throw new AuthorizationException();
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'ntn' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $supplier->update($data);

        return response()->json($supplier);
    }
}
