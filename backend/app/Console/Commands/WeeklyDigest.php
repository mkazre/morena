<?php

namespace App\Console\Commands;

use App\Models\Deal;
use App\Models\Lead;
use App\Models\Listing;
use App\Models\Setting;
use App\Models\Task;
use App\Services\Mailer;
use App\Services\Notifier;
use Illuminate\Console\Command;

/** Monday-morning performance email to admins and branch managers (their own branch). */
class WeeklyDigest extends Command
{
    protected $signature = 'morena:weekly-digest {--dry-run : Print instead of emailing}';

    protected $description = 'Weekly performance digest for admins and branch managers';

    public function handle(Notifier $notify, Mailer $mailer): int
    {
        $from = now()->subWeek()->startOfDay();
        $company = Setting::current()->company_name;
        $recipients = $notify->role(['admin', 'branch_manager']);
        foreach ($recipients as $user) {
            $branch = $user->hasRole('branch_manager') ? $user->branch_id : null;
            $deals = Deal::query()->when($branch, fn ($q) => $q->where('branch_id', $branch));
            $closed = (clone $deals)->where('status', 'closed')->where('closed_at', '>=', $from)->get();
            $lines = [
                'Deals closed: '.$closed->count().' (R '.number_format($closed->sum('sale_value')).')',
                'New deals opened: '.(clone $deals)->where('created_at', '>=', $from)->count(),
                'Open pipeline: R '.number_format((clone $deals)->where('status', 'open')->sum('sale_value')).' across '.(clone $deals)->where('status', 'open')->count().' deals',
                'New leads: '.Lead::where('created_at', '>=', $from)->when($branch, fn ($q) => $q->whereHas('agent', fn ($a) => $a->where('branch_id', $branch)))->count(),
                'New listings: '.Listing::where('created_at', '>=', $from)->when($branch, fn ($q) => $q->where('branch_id', $branch))->count(),
                'Overdue tasks: '.Task::whereNull('completed_at')->where('due_at', '<', now())->when($branch, fn ($q) => $q->whereHas('assignee', fn ($a) => $a->where('branch_id', $branch)))->count(),
            ];
            $body = 'Hi '.strtok($user->name, ' ').",\n\nHere's ".($branch ? 'your branch' : $company)."'s week (".$from->format('j M').' – '.now()->format('j M')."):\n\n• "
                .implode("\n• ", $lines)."\n\nOpen the CRM: ".url('/')."\n\n— Morena CRM";
            if ($this->option('dry-run')) {
                $this->line("To {$user->email}\n{$body}\n");

                continue;
            }
            $mailer->send($user->email, $company.' — weekly summary', $body);
            $notify->send($user, 'digest', 'Your weekly summary is ready', $lines[0], '/admin/Dashboard.html', 'digest-'.now()->format('o-W'));
        }
        $this->info('Digest sent to '.$recipients->count().' recipient(s)');

        return self::SUCCESS;
    }
}
