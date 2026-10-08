<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public const ROLES = ['admin', 'branch_manager', 'hr', 'agent', 'landlord', 'tenant'];

    public function index()
    {
        $users = User::with(['roles', 'branch'])->orderBy('name')->get();

        return ['data' => $users->map(fn (User $u) => $this->present($u))];
    }

    /** POST /users/invite — creates the account and emails a set-password link. */
    public function invite(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(self::ROLES)],
            'branch_id' => ['nullable', 'exists:branches,id'],
        ]);
        $user = User::create([...collect($data)->except('role')->all(), 'status' => 'invited']);
        $user->assignRole($data['role']);
        Password::broker()->sendResetLink(['email' => $user->email]);

        return $this->present($user->load(['roles', 'branch']));
    }

    public function updateRole(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(self::ROLES)],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'branch_id' => ['nullable', 'exists:branches,id'],
        ]);
        abort_if($user->is($request->user()) && (($data['role'] ?? 'admin') !== 'admin' || ($data['status'] ?? 'active') !== 'active'),
            422, 'You cannot remove your own admin access.');

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }
        $user->update(collect($data)->only(['status', 'branch_id'])->all());

        return $this->present($user->fresh(['roles', 'branch']));
    }

    private function present(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->primaryRole(),
            'branch' => $u->branch?->name,
            'branch_id' => $u->branch_id,
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'status' => $u->status,
        ];
    }
}
