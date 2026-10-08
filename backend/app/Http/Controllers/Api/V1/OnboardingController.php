<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OnboardingChecklist;
use App\Models\OnboardingTask;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OnboardingController extends Controller
{
    public function index()
    {
        $lists = OnboardingChecklist::with(['staff.user', 'tasks'])->latest('start_date')->get()
            // Hide hires whose checklist is complete and who started more than 30 days ago.
            ->reject(fn ($c) => $c->tasks->every('is_done') && $c->start_date->lt(now()->subDays(30)));

        return ['data' => $lists->values()->map(fn ($c) => $this->present($c))];
    }

    /** POST /onboarding { staff_id, start_date?, role_title? } — creates the default checklist. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'staff_id' => ['required', 'exists:staff,id', 'unique:onboarding_checklists,staff_id'],
            'start_date' => ['nullable', 'date'],
            'role_title' => ['nullable', 'string', 'max:255'],
        ]);
        $staff = Staff::findOrFail($data['staff_id']);

        $checklist = DB::transaction(function () use ($data, $staff) {
            $c = OnboardingChecklist::create([
                'staff_id' => $staff->id,
                'start_date' => $data['start_date'] ?? $staff->start_date ?? now(),
                'role_title' => $data['role_title'] ?? $staff->role_title,
            ]);
            foreach (OnboardingChecklist::DEFAULT_TASKS as $i => $label) {
                $c->tasks()->create(['label' => $label, 'order' => $i]);
            }

            return $c;
        });

        return $this->present($checklist->load(['staff.user', 'tasks']));
    }

    public function updateTask(Request $request, OnboardingTask $task)
    {
        $task->update($request->validate(['is_done' => ['required', 'boolean']]));

        return $this->present($task->checklist->load(['staff.user', 'tasks']));
    }

    private function present(OnboardingChecklist $c): array
    {
        $total = max($c->tasks->count(), 1);

        return [
            'id' => $c->id,
            'name' => $c->staff->user->name,
            'role' => $c->role_title,
            'start_date' => $c->start_date->toDateString(),
            'pct' => (int) round($c->tasks->where('is_done', true)->count() / $total * 100),
            'tasks' => $c->tasks->map(fn ($t) => ['id' => $t->id, 'label' => $t->label, 'is_done' => $t->is_done])->values(),
        ];
    }
}
