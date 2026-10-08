<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseCompletion;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseController extends Controller
{
    private function agents()
    {
        return Staff::where('department', 'sales')->where('status', '!=', 'terminated');
    }

    public function index(Request $request)
    {
        $staffId = $request->user()->staff?->id;
        $totalAgents = $this->agents()->count();
        $courses = Course::withCount('lessons')
            ->withCount(['completions as completed_count' => fn ($q) => $q->where('status', 'complete')])
            ->orderBy('category')->orderBy('title')->get();

        $mine = $staffId ? CourseCompletion::where('staff_id', $staffId)->get()->keyBy('course_id') : collect();
        $myLessons = $staffId
            ? LessonCompletion::where('staff_id', $staffId)->join('lessons', 'lessons.id', '=', 'lesson_completions.lesson_id')
                ->selectRaw('lessons.course_id, count(*) as n')->groupBy('lessons.course_id')->pluck('n', 'course_id')
            : collect();

        return ['data' => $courses->map(fn (Course $c) => [
            'id' => $c->id,
            'category' => $c->category,
            'title' => $c->title,
            'lessons' => $c->lessons_count,
            'duration' => $c->durationLabel(),
            'duration_minutes' => $c->duration_minutes,
            'completed_count' => $c->completed_count,
            'total_agents' => $totalAgents,
            'my_status' => $mine->get($c->id)?->status ?? 'not_started',
            'my_completed_at' => $mine->get($c->id)?->completed_at?->toIso8601String(),
            'my_lessons_done' => (int) ($myLessons[$c->id] ?? 0),
        ])];
    }

    public function show(Request $request, Course $course)
    {
        $staffId = $request->user()->staff?->id;
        $course->load('lessons');
        $done = $staffId
            ? LessonCompletion::where('staff_id', $staffId)->whereIn('lesson_id', $course->lessons->pluck('id'))->pluck('lesson_id')
            : collect();

        $completions = CourseCompletion::where('course_id', $course->id)->get()->keyBy('staff_id');
        $lessonCounts = LessonCompletion::whereIn('lesson_id', $course->lessons->pluck('id'))
            ->selectRaw('staff_id, count(*) as n')->groupBy('staff_id')->pluck('n', 'staff_id');

        return [
            'id' => $course->id,
            'title' => $course->title,
            'category' => $course->category,
            'duration' => $course->durationLabel(),
            'lessons' => $course->lessons->map(fn ($l) => [
                'id' => $l->id, 'title' => $l->title,
                'duration' => $l->duration_minutes.' min', 'done' => $done->contains($l->id),
            ]),
            'agents' => $this->agents()->with('user')->get()->sortBy('user.name')->values()->map(fn ($s) => [
                'name' => $s->user->name,
                'status' => $completions->get($s->id)?->status ?? 'not_started',
                'lessons_done' => (int) ($lessonCounts[$s->id] ?? 0),
                'lessons_total' => $course->lessons->count(),
            ]),
        ];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $course = DB::transaction(function () use ($data) {
            $course = Course::create(collect($data)->except('lessons')->all());
            $this->syncLessons($course, $data['lessons'] ?? []);

            return $course;
        });

        return $this->show($request, $course);
    }

    public function update(Request $request, Course $course)
    {
        $data = $this->validated($request, partial: true);
        DB::transaction(function () use ($course, $data) {
            $course->update(collect($data)->except('lessons')->all());
            if (array_key_exists('lessons', $data)) {
                $this->syncLessons($course, $data['lessons'] ?? []);
            }
        });

        return $this->show($request, $course->fresh());
    }

    /** PUT /lessons/{id}/complete — marks the lesson done for the signed-in staff member. */
    public function completeLesson(Request $request, Lesson $lesson)
    {
        $staffId = $request->user()->staff?->id;
        abort_unless($staffId, 422, 'Only staff members can complete lessons.');

        DB::transaction(function () use ($lesson, $staffId) {
            LessonCompletion::firstOrCreate(['lesson_id' => $lesson->id, 'staff_id' => $staffId], ['completed_at' => now()]);
            $total = $lesson->course->lessons()->count();
            $done = LessonCompletion::where('staff_id', $staffId)->whereIn('lesson_id', $lesson->course->lessons()->pluck('id'))->count();
            CourseCompletion::updateOrCreate(['course_id' => $lesson->course_id, 'staff_id' => $staffId], [
                'status' => $done >= $total ? 'complete' : 'in_progress',
                'completed_at' => $done >= $total ? now() : null,
            ]);
        });

        return $this->show($request, $lesson->course);
    }

    /** Lessons are given as an ordered list of {title, duration_minutes}; existing ones are matched by title. */
    private function syncLessons(Course $course, array $lessons): void
    {
        $keep = [];
        foreach (array_values($lessons) as $i => $l) {
            $lesson = $course->lessons()->firstOrNew(['title' => $l['title']]);
            $lesson->fill(['duration_minutes' => $l['duration_minutes'] ?? 10, 'order' => $i])->save();
            $keep[] = $lesson->id;
        }
        $course->lessons()->whereNotIn('id', $keep)->delete();
        $course->update(['duration_minutes' => $course->lessons()->sum('duration_minutes')]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$req, 'string', 'max:255'],
            'category' => [$req, 'string', 'max:100'],
            'lessons' => ['sometimes', 'array'],
            'lessons.*.title' => ['required', 'string', 'max:255'],
            'lessons.*.duration_minutes' => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
