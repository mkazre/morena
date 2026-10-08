<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;

/**
 * Writes created / updated / deleted events to audit_logs with a field-level diff.
 * Models may define auditLabel() for a readable description; secrets are never logged.
 */
trait Audited
{
    protected static array $auditIgnore = ['updated_at', 'created_at', 'password', 'remember_token', 'two_factor_secret',
        'two_factor_recovery_codes', 'last_login_at', 'failed_logins', 'locked_until', 'calendar_token'];

    protected static function bootAudited(): void
    {
        static::created(fn ($m) => $m->writeAudit('created'));
        static::updated(fn ($m) => $m->writeAudit('updated'));
        static::deleted(fn ($m) => $m->writeAudit('deleted'));
    }

    protected function writeAudit(string $event): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests() && ! auth()->check()) {
            return; // seeders / migrations
        }
        $changes = null;
        if ($event === 'updated') {
            $changes = [];
            foreach ($this->getChanges() as $field => $new) {
                if (in_array($field, static::$auditIgnore, true)) {
                    continue;
                }
                $changes[$field] = [$this->getOriginal($field), $new];
            }
            if (! $changes) {
                return;
            }
        }
        AuditLog::write($event, method_exists($this, 'auditLabel') ? $this->auditLabel() : class_basename($this).' #'.$this->getKey(), $this, $changes);
    }
}
