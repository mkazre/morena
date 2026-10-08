<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\CommsController;
use App\Http\Controllers\Api\V1\Finance\ExtrasController;
use App\Models\Accounting\Invoice;
use App\Models\Appointment;
use App\Models\Certification;
use App\Models\Contract;
use App\Models\EmailLog;
use App\Models\Listing;
use App\Models\Task;
use App\Services\Mailer;
use App\Services\Notifier;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs once a day via the Laravel scheduler (one cPanel cron: `php artisan schedule:run` every minute).
 * Every step is idempotent: notifications carry dedupe keys and reminders check the email log.
 */
class DailyJobs extends Command
{
    protected $signature = 'morena:daily';

    protected $description = 'Recurring documents, overdue reminders, expiry alerts, task and viewing reminders';

    public function handle(Notifier $notify, Mailer $mailer): int
    {
        $steps = [
            'Recurring invoices & bills' => fn () => ExtrasController::runRecurring(),
            'Lead scores refreshed' => fn () => \App\Services\LeadScoring::rescoreAll(),
            'Overdue invoice reminders' => fn () => $this->overdueReminders($mailer),
            'FFC / CPD alerts' => fn () => $this->ffcAlerts($notify),
            'Contract renewals' => fn () => $this->contractAlerts($notify),
            'Mandate expiries' => fn () => $this->mandateAlerts($notify),
            'Tasks due today' => fn () => $this->taskAlerts($notify),
            'Tomorrow\'s appointments' => fn () => $this->appointmentAlerts($notify),
        ];
        foreach ($steps as $label => $step) {
            try {
                $this->info(str_pad($label, 30).' '.$step());
            } catch (Throwable $e) {
                report($e);
                $this->error($label.' failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /** Email customers with overdue invoices, at most once every 7 days per invoice. */
    private function overdueReminders(Mailer $mailer): int
    {
        $sent = 0;
        foreach (Invoice::with(['contact', 'lines'])->whereIn('status', ['approved', 'partial'])->where('due_date', '<', now()->toDateString())->get() as $inv) {
            if (! $inv->contact->email || EmailLog::where('related_type', Invoice::class)->where('related_id', $inv->id)
                ->where('subject', 'like', 'Reminder:%')->where('created_at', '>=', now()->subDays(7))->exists()) {
                continue;
            }
            $mailer->send($inv->contact->email, ...CommsController::invoiceEmail($inv, 'invoice_reminder'), related: $inv);
            $sent++;
        }

        return $sent;
    }

    private function ffcAlerts(Notifier $notify): int
    {
        $n = 0;
        $hr = $notify->role(['admin', 'hr']);
        foreach (Certification::with('staff.user')->whereNotNull('ffc_expiry')->where('ffc_expiry', '<=', now()->addDays(60))->get() as $c) {
            $days = (int) now()->startOfDay()->diffInDays($c->ffc_expiry, false);
            $title = $days < 0 ? 'FFC expired: '.$c->staff->user->name : 'FFC expires in '.$days.' days: '.$c->staff->user->name;
            $key = 'ffc-'.$c->id.'-'.$c->ffc_expiry->toDateString().'-'.($days < 0 ? 'expired' : ($days <= 14 ? '14' : '60'));
            $notify->send($hr->concat([$c->staff->user])->unique('id'), 'expiry', $title, 'Agents may not trade without a valid Fidelity Fund Certificate (PPRA).', '/training/Certifications.html', $key);
            $n++;
        }
        // CPD reminder in the last quarter of the year.
        if (now()->month >= 10) {
            foreach (Certification::with('staff.user')->whereColumn('cpd_points', '<', 'cpd_target')->get() as $c) {
                $notify->send($c->staff->user, 'expiry', 'CPD points: '.$c->cpd_points.' of '.$c->cpd_target, 'Complete your CPD before 31 December.', '/portal/MyTraining.html', 'cpd-'.$c->id.'-'.now()->year.'-'.now()->month);
            }
        }

        return $n;
    }

    private function contractAlerts(Notifier $notify): int
    {
        $n = 0;
        foreach (Contract::with('staff.user')->whereNotNull('expiry_date')->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(60)->toDateString()])->get() as $c) {
            $notify->send($notify->role(['admin', 'hr']), 'expiry', 'Contract renewal due: '.$c->staff->user->name, $c->type.' ends '.$c->expiry_date->format('j M Y'), '/hr/Contracts.html', 'contract-'.$c->id.'-'.$c->expiry_date->toDateString());
            $n++;
        }

        return $n;
    }

    private function mandateAlerts(Notifier $notify): int
    {
        $n = 0;
        foreach (Listing::with('agent.user')->where('status', 'active')->whereNotNull('mandate_expiry')->where('mandate_expiry', '<=', now()->addDays(30)->toDateString())->get() as $l) {
            $expired = $l->mandate_expiry->isPast();
            $to = $l->agent?->user ? collect([$l->agent->user]) : collect();
            $notify->send($to->merge($notify->role(['branch_manager'], $l->branch_id)), 'expiry',
                ($expired ? 'Mandate expired: ' : 'Mandate expiring: ').$l->title, ($expired ? 'Expired ' : 'Expires ').$l->mandate_expiry->format('j M Y').' — renew or relist.',
                '/sales/Brochure.html?id='.$l->id, 'mandate-'.$l->id.'-'.$l->mandate_expiry->toDateString().($expired ? '-x' : ''));
            $n++;
        }

        return $n;
    }

    private function taskAlerts(Notifier $notify): int
    {
        $tasks = Task::with('assignee')->whereNull('completed_at')->whereDate('due_at', now()->toDateString())->get();
        foreach ($tasks as $t) {
            $notify->send($t->assignee, 'task_due', 'Due today: '.$t->title, null, '/work/Tasks.html', 'task-'.$t->id.'-'.now()->toDateString());
        }

        return $tasks->count();
    }

    private function appointmentAlerts(Notifier $notify): int
    {
        $appts = Appointment::with('user')->where('status', 'scheduled')->whereDate('starts_at', now()->addDay()->toDateString())->get();
        foreach ($appts as $a) {
            $notify->send($a->user, 'reminder', 'Tomorrow '.$a->starts_at->format('H:i').': '.$a->title, trim($a->location.' '.$a->attendee_name), '/work/Calendar.html', 'appt-'.$a->id);
        }

        return $appts->count();
    }
}
