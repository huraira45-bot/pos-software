<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\PosPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index()
    {
        return response()->json(Branch::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:branches,code'],
            'name' => ['required', 'string', 'max:255'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'ntn' => ['required', 'string', 'max:50'],
            'strn' => ['nullable', 'string', 'max:50'],
            'tax_office_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        return response()->json(Branch::create($data)->refresh(), 201);
    }

    public function show(Branch $branch)
    {
        return response()->json($branch->load('terminals'));
    }

    public function update(Request $request, Branch $branch)
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'address_line1' => ['sometimes', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:100'],
            'ntn' => ['sometimes', 'string', 'max:50'],
            'strn' => ['nullable', 'string', 'max:50'],
            'tax_office_name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $branch->update($data);

        return response()->json($branch);
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can(PosPermissions::TERMINAL_MANAGE)) {
            throw new AuthorizationException();
        }
    }
}
