<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeaveController extends Controller
{
    /** Admin/HR: everyone. Branch manager: own branch. Agent: own requests only. */
    private function scoped(User $user): Builder
    {
        $q = LeaveRequest::query();
        if ($user->hasAnyRole(['admin', 'hr'])) {
            return $q;
        }
        if ($user->hasRole('branch_manager')) {
            return $q->whereHas('staff', fn ($s) => $s->where('branch_id', $user->branch_id));
        }

        return $q->where('staff_id', $user->staff?->id ?? 0);
    }

    public function index(Request $request)
    {
        $q = $this->scoped($request->user())->with('staff.user')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('start_date');

        return ['data' => $q->get()->map(fn ($r) => $this->present($r))];
    }

    /** POST /leave-requests — staff request their own leave; HR may capture leave for anyone. */
    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'staff_id' => ['nullable', 'exists:staff,id'],
            'type' => ['required', Rule::in(array_keys(LeaveRequest::TYPES))],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'days' => ['nullable', 'numeric', 'min:0.5'],
        ]);
        $isHr = $user->hasAnyRole(['admin', 'hr']);
        $data['staff_id'] = $isHr && ! empty($data['staff_id']) ? $data['staff_id'] : $user->staff?->id;
        abort_unless($data['staff_id'], 422, 'No staff profile to record leave against.');
        $data['days'] ??= self::workingDays(Carbon::parse($data['start_date']), Carbon::parse($data['end_date']));

        $leave = LeaveRequest::create($data);
        if (! ($isHr && $request->boolean('approved'))) {
            $staff = $leave->staff()->with('user')->first();
            $notifier = app(\App\Services\Notifier::class);
            $notifier->send($notifier->role(['hr', 'admin'])->concat($notifier->role(['branch_manager'], $staff->branch_id)), 'leave_request',
                $staff->user->name.' requested '.strtolower(LeaveRequest::TYPES[$leave->type]), $leave->start_date->format('j M').' – '.$leave->end_date->format('j M Y').' · '.$leave->days.' days',
                '/hr/Leave.html', 'leave-req-'.$leave->id);
        }
        // Leave captured directly by HR is already approved.
        if ($isHr && $request->boolean('approved')) {
            $this->decide($leave, 'approved', $user);
        }

        return $this->present($leave->load('staff.user'));
    }

    public function approve(Request $request, LeaveRequest $leaveRequest)
    {
        return $this->present($this->decide($this->authorized($request, $leaveRequest), 'approved', $request->user()));
    }

    public function decline(Request $request, LeaveRequest $leaveRequest)
    {
        return $this->present($this->decide($this->authorized($request, $leaveRequest), 'declined', $request->user()));
    }

    public function balances(Request $request)
    {
        $user = $request->user();
        $q = Staff::with(['user', 'leaveBalance'])->whereHas('user')
            ->when($user->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->where('status', '!=', 'terminated');

        return ['data' => $q->get()->sortBy('user.name')->values()->map(fn ($s) => [
            'staff_id' => $s->id,
            'name' => $s->user->name,
            'annual' => $s->leaveBalance?->annual_days ?? 0,
            'sick' => $s->leaveBalance?->sick_days ?? 0,
            'taken' => $s->leaveBalance?->taken_days ?? 0,
        ])];
    }

    private function authorized(Request $request, LeaveRequest $leave): LeaveRequest
    {
        abort_unless($this->scoped($request->user())->whereKey($leave->id)->exists(), 404);
        abort_if($leave->status !== 'pending', 422, 'This request has already been decided.');

        return $leave;
    }

    private function decide(LeaveRequest $leave, string $status, User $by): LeaveRequest
    {
        DB::transaction(function () use ($leave, $status, $by) {
            $leave->update(['status' => $status, 'approved_by' => $by->id, 'decided_at' => now()]);
            app(\App\Services\Notifier::class)->send($leave->staff->user_id, 'leave_decision', 'Your leave was '.$status,
                $leave->start_date->format('j M').' – '.$leave->end_date->format('j M Y').' · by '.$by->name, null, 'leave-dec-'.$leave->id);
            if ($status === 'approved') {
                $balance = LeaveBalance::firstOrCreate(['staff_id' => $leave->staff_id, 'year' => $leave->start_date->year]);
                $column = $leave->type === 'sick' ? 'sick_days' : 'annual_days';
                $balance->update([
                    $column => max(0, $balance->{$column} - $leave->days),
                    'taken_days' => $balance->taken_days + $leave->days,
                ]);
            }
        });

        return $leave->fresh('staff.user');
    }

    public static function workingDays(Carbon $from, Carbon $to): float
    {
        $days = 0;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if (! $d->isWeekend()) {
                $days++;
            }
        }

        return max($days, 1);
    }

    private function present(LeaveRequest $r): array
    {
        return [
            'id' => $r->id,
            'staff_id' => $r->staff_id,
            'name' => $r->staff->user->name,
            'type' => $r->type,
            'type_label' => LeaveRequest::TYPES[$r->type],
            'start_date' => $r->start_date->toDateString(),
            'end_date' => $r->end_date->toDateString(),
            'days' => $r->days,
            'status' => $r->status,
        ];
    }
}
