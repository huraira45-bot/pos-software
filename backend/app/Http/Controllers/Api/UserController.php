<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\PosPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeManage($request);

        $users = User::query()
            ->when($request->integer('branch_id'), fn ($q, $v) => $q->where('branch_id', $v))
            ->with('roles:name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $this->present($u));

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(['cashier', 'manager', 'admin'])],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'branch_id' => $data['branch_id'] ?? null,
            'phone' => $data['phone'] ?? null,
        ]);
        $user->assignRole($data['role']);

        AuditLog::record('user.created', $user, null, ['email' => $user->email, 'role' => $data['role']]);

        return response()->json($this->present($user->fresh('roles')), 201);
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['sometimes', Rule::in(['cashier', 'manager', 'admin'])],
        ]);

        $user->update(collect($data)->except('role')->all());

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return response()->json($this->present($user->fresh('roles')));
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'branch_id' => $user->branch_id,
            'phone' => $user->phone,
            'is_active' => (bool) $user->is_active,
            'roles' => $user->roles->pluck('name'),
        ];
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can(PosPermissions::USER_MANAGE)) {
            throw new AuthorizationException();
        }
    }
}
