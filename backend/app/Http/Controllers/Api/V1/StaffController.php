<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\StaffResource;
use App\Models\Commission;
use App\Models\Course;
use App\Models\Deal;
use App\Models\LeaveBalance;
use App\Models\Staff;
use App\Models\User;
use App\Services\CommissionService;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $q = Staff::query()->with(['user', 'branch'])->whereHas('user')
            ->when($user->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when($request->filled('department'), fn ($q) => $q->where('department', $request->string('department')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('user', fn ($u) => $u->where('name', 'like', '%'.$request->string('search').'%'))
                ->orWhere('role_title', 'like', '%'.$request->string('search').'%')))
            ->join('users', 'users.id', '=', 'staff.user_id')->orderBy('users.name')->select('staff.*');

        return StaffResource::collection($q->paginate(min($request->integer('per_page', 100), 200)));
    }

    public function show(Request $request, Staff $staff, CommissionService $commissions)
    {
        $this->authorizeView($request->user(), $staff);
        $staff->load(['user', 'branch', 'leaveBalance', 'documents.category', 'contracts']);

        $year = now()->startOfYear();
        $closedYtd = Deal::forAgent($staff->id)->where('status', 'closed')->where('closed_at', '>=', $year)->count();
        $commissionYtd = Commission::involvingAgent($staff->id)->where('status', 'paid')->where('paid_at', '>=', $year)
            ->get()->sum(fn ($c) => $c->shareFor($staff->id));
        $assigned = Course::count();
        $completed = $staff->courses()->wherePivot('status', 'complete')->count();
        $tier = $staff->isAgent() ? $commissions->resolveTier($staff) : null;
        $balance = $staff->leaveBalance;

        return [
            'staff' => new StaffResource($staff),
            'stats' => [
                'deals_closed_ytd' => $closedYtd,
                'commission_ytd' => round($commissionYtd, 2),
                'leave_balance_days' => $balance?->annual_days,
                'training_completed' => $completed,
                'training_total' => $assigned,
            ],
            'contract_type' => $staff->contracts->sortByDesc('start_date')->first()?->type,
            'commission_tier' => $tier ? ['name' => $tier->name, 'label' => $tier->splitLabel(), 'agent_split_percent' => $tier->agent_split_percent] : null,
            'documents' => DocumentResource::collection($staff->documents),
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role_title' => ['required', 'string', 'max:255'],
            'department' => ['required', Rule::in(['sales', 'hr', 'admin', 'finance'])],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'employment_type' => ['required', Rule::in(['independent_contractor', 'permanent'])],
            'start_date' => ['nullable', 'date'],
            'ffc_number' => ['nullable', 'string', 'max:50'],
            'ffc_expiry' => ['nullable', 'date'],
            'system_role' => ['sometimes', Rule::in(['admin', 'branch_manager', 'hr', 'agent'])],
        ]);

        $staff = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null,
                'branch_id' => $data['branch_id'] ?? null, 'status' => 'invited',
            ]);
            $user->assignRole($data['system_role'] ?? ($data['department'] === 'hr' ? 'hr' : 'agent'));
            $staff = $user->staff()->create(collect($data)->except(['name', 'email', 'phone', 'system_role'])->all());
            LeaveBalance::create(['staff_id' => $staff->id, 'year' => now()->year]);
            if ($staff->isAgent()) {
                $staff->certification()->create([
                    'ffc_number' => $staff->ffc_number, 'ffc_expiry' => $staff->ffc_expiry, 'status' => 'pending',
                ]);
            }

            return $staff;
        });
        Password::broker()->sendResetLink(['email' => $data['email']]); // set-password invite

        return new StaffResource($staff->load(['user', 'branch']));
    }

    public function update(Request $request, Staff $staff)
    {
        $user = $request->user();
        $this->authorizeView($user, $staff);
        $isHr = $user->hasAnyRole(['admin', 'hr']);
        abort_unless($isHr || $user->staff?->id === $staff->id, 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($staff->user_id)],
            'role_title' => ['sometimes', 'string', 'max:255'],
            'department' => ['sometimes', Rule::in(['sales', 'hr', 'admin', 'finance'])],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'employment_type' => ['sometimes', Rule::in(['independent_contractor', 'permanent'])],
            'start_date' => ['nullable', 'date'],
            'ffc_number' => ['nullable', 'string', 'max:50'],
            'ffc_expiry' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(['active', 'on_leave', 'terminated'])],
        ]);
        if (! $isHr) {
            $data = collect($data)->only(['phone'])->all(); // self-service: contact number only
        }

        DB::transaction(function () use ($staff, $data) {
            $userFields = collect($data)->only(['name', 'phone', 'email'])->all();
            if (array_key_exists('branch_id', $data)) {
                $userFields['branch_id'] = $data['branch_id'];
            }
            $staff->user->update($userFields);
            $staff->update(collect($data)->except(['name', 'phone', 'email'])->all());
            if ($staff->certification && (isset($data['ffc_number']) || isset($data['ffc_expiry']))) {
                $staff->certification->update(collect($data)->only(['ffc_number', 'ffc_expiry'])->all());
            }
        });

        return new StaffResource($staff->fresh(['user', 'branch']));
    }

    private function authorizeView(User $user, Staff $staff): void
    {
        $ok = Scope::isOrgWide($user)
            || ($user->hasRole('branch_manager') && $staff->branch_id === $user->branch_id)
            || $user->staff?->id === $staff->id;
        abort_unless($ok, 404);
    }
}
