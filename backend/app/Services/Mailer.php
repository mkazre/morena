<?php

namespace App\Services;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Plain-text email to clients, logged against the record it relates to (lead, deal, invoice…). */
class Mailer
{
    public function send(string $to, string $subject, string $body, ?Model $related = null, ?User $by = null, ?string $replyTo = null): EmailLog
    {
        $log = ['user_id' => $by?->id, 'to' => $to, 'subject' => $subject, 'body' => $body,
            'related_type' => $related ? $related::class : null, 'related_id' => $related?->getKey(), 'created_at' => now()];
        try {
            Mail::raw($body, function ($m) use ($to, $subject, $replyTo, $by) {
                $m->to($to)->subject($subject);
                if ($replyTo ?? $by?->email) {
                    $m->replyTo($replyTo ?? $by->email, $by?->name);
                }
            });

            return EmailLog::create($log + ['status' => 'sent']);
        } catch (Throwable $e) {
            report($e);

            return EmailLog::create($log + ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)]);
        }
    }

    /** Replace {placeholders}; unknown ones are left blank. */
    public static function fill(string $text, array $values): string
    {
        return preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($values[$m[1]] ?? ''), $text);
    }
}
