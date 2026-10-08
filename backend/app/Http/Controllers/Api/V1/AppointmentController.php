<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Task;
use App\Models\User;
use App\Services\Notifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Viewings, valuations and meetings, plus a private iCal feed so they appear in phone calendars. */
class AppointmentController extends Controller
{
    public const TYPES = ['viewing', 'valuation', 'meeting', 'signing', 'inspection', 'other'];

    public function index(Request $request)
    {
        $user = $request->user();
        $from = Carbon::parse($request->input('from', now()->startOfWeek()));
        $to = Carbon::parse($request->input('to', now()->endOfWeek()->addWeeks(4)));
        $q = Appointment::with(['user', 'listing', 'lead'])->whereBetween('starts_at', [$from, $to->copy()->endOfDay()])
            ->when(! $user->hasAnyRole(['admin', 'branch_manager']) || $request->input('scope', 'mine') === 'mine', fn ($q) => $q->where('user_id', $user->id))
            ->when($user->hasRole('branch_manager') && $request->input('scope') === 'team', fn ($q) => $q->whereHas('user', fn ($u) => $u->where('branch_id', $user->branch_id)))
            ->orderBy('starts_at');

        return ['data' => $q->get()->map(fn ($a) => $this->present($a))];
    }

    public function store(Request $request, Notifier $notifier)
    {
        $data = $this->validated($request);
        $user = $request->user();
        $data['user_id'] = $user->hasAnyRole(['admin', 'branch_manager']) ? ($data['user_id'] ?? $user->id) : $user->id;
        $data['ends_at'] ??= Carbon::parse($data['starts_at'])->addHour();
        $appt = Appointment::create($data);
        if ($appt->user_id !== $user->id) {
            $notifier->send($appt->user_id, 'appointment', 'New '.$appt->type.' booked for you', $appt->title.' · '.$appt->starts_at->format('D j M H:i'), '/work/Calendar.html');
        }

        return $this->present($appt->load(['user', 'listing', 'lead']));
    }

    public function update(Request $request, Appointment $appointment)
    {
        $this->authorizeAppt($request->user(), $appointment);
        $appointment->update($this->validated($request, true));

        return $this->present($appointment->fresh(['user', 'listing', 'lead']));
    }

    public function destroy(Request $request, Appointment $appointment)
    {
        $this->authorizeAppt($request->user(), $appointment);
        $appointment->delete();

        return response()->noContent();
    }

    /** Private subscription URL (rotating it invalidates the old one). */
    public function feedUrl(Request $request)
    {
        $user = $request->user();
        if (! $user->calendar_token || $request->boolean('rotate')) {
            $user->forceFill(['calendar_token' => Str::random(40)])->save();
        }

        return ['url' => url('/calendar/'.$user->calendar_token.'.ics')];
    }

    /** Public (token-protected) iCalendar feed: appointments + open tasks with a due date. */
    public static function feed(string $token)
    {
        $user = User::where('calendar_token', $token)->where('status', 'active')->firstOrFail();
        $esc = fn ($s) => str_replace(["\\", ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], (string) $s);
        $fmt = fn (Carbon $d) => $d->copy()->utc()->format('Ymd\THis\Z');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Morena Properties//CRM//EN', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:Morena — '.$esc($user->name)];
        foreach (Appointment::where('user_id', $user->id)->where('starts_at', '>=', now()->subMonths(2))->where('status', '!=', 'cancelled')->get() as $a) {
            $lines = array_merge($lines, ['BEGIN:VEVENT', 'UID:appt-'.$a->id.'@morena-crm', 'DTSTAMP:'.$fmt($a->updated_at), 'DTSTART:'.$fmt($a->starts_at),
                'DTEND:'.$fmt($a->ends_at), 'SUMMARY:'.$esc(ucfirst($a->type).': '.$a->title), 'LOCATION:'.$esc($a->location),
                'DESCRIPTION:'.$esc(trim(($a->attendee_name ? 'With '.$a->attendee_name.' '.$a->attendee_phone."\n" : '').$a->notes)), 'END:VEVENT']);
        }
        foreach (Task::where('assigned_to', $user->id)->whereNull('completed_at')->whereNotNull('due_at')->get() as $t) {
            $lines = array_merge($lines, ['BEGIN:VEVENT', 'UID:task-'.$t->id.'@morena-crm', 'DTSTAMP:'.$fmt($t->updated_at),
                'DTSTART:'.$fmt($t->due_at), 'DTEND:'.$fmt($t->due_at->copy()->addMinutes(30)), 'SUMMARY:'.$esc('Task: '.$t->title), 'END:VEVENT']);
        }
        $lines[] = 'END:VCALENDAR';

        return response(implode("\r\n", $lines)."\r\n", 200, ['Content-Type' => 'text/calendar; charset=utf-8']);
    }

    private function authorizeAppt(User $u, Appointment $a): void
    {
        abort_unless($a->user_id === $u->id || $u->hasRole('admin') || ($u->hasRole('branch_manager') && $a->user?->branch_id === $u->branch_id), 404);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'type' => [$partial ? 'sometimes' : 'required', Rule::in(self::TYPES)],
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'starts_at' => [$partial ? 'sometimes' : 'required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'exists:users,id'],
            'listing_id' => ['nullable', 'exists:listings,id'], 'lead_id' => ['nullable', 'exists:leads,id'], 'deal_id' => ['nullable', 'exists:deals,id'],
            'attendee_name' => ['nullable', 'string', 'max:120'], 'attendee_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['scheduled', 'done', 'cancelled', 'no_show'])],
        ]);
    }

    private function present(Appointment $a): array
    {
        return [
            'id' => $a->id, 'type' => $a->type, 'title' => $a->title, 'status' => $a->status,
            'starts_at' => $a->starts_at->toIso8601String(), 'ends_at' => $a->ends_at->toIso8601String(),
            'location' => $a->location, 'user_id' => $a->user_id, 'agent' => $a->user?->name,
            'listing_id' => $a->listing_id, 'listing' => $a->listing?->title, 'lead_id' => $a->lead_id, 'lead' => $a->lead?->name,
            'attendee_name' => $a->attendee_name, 'attendee_phone' => $a->attendee_phone, 'notes' => $a->notes,
        ];
    }
}
