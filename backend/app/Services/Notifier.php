<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/** In-app notifications (the bell). A dedupe key makes repeated daily checks idempotent. */
class Notifier
{
    /** @param  User|iterable<User>|int|null  $to */
    public function send(User|iterable|int|null $to, string $type, string $title, ?string $body = null, ?string $url = null, ?string $dedupe = null): void
    {
        foreach ($this->users($to) as $user) {
            if (auth()->id() === $user->id && ! in_array($type, ['reminder', 'task_due', 'expiry', 'digest'], true)) {
                continue; // don't notify people about their own actions
            }
            $fields = ['type' => $type, 'title' => $title, 'body' => $body, 'url' => $url];
            try {
                $dedupe
                    ? AppNotification::firstOrCreate(['user_id' => $user->id, 'dedupe_key' => $dedupe], $fields)
                    : AppNotification::create(['user_id' => $user->id] + $fields);
            } catch (UniqueConstraintViolationException) {
                // concurrent duplicate — fine
            }
        }
    }

    /** Users holding any of $roles (optionally limited to a branch for managers). */
    public function role(array $roles, ?int $branchId = null): Collection
    {
        return User::role($roles)->where('status', 'active')
            ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)->orWhereHas('roles', fn ($r) => $r->where('name', '!=', 'branch_manager'))))
            ->get();
    }

    private function users(User|iterable|int|null $to): iterable
    {
        if ($to === null) {
            return [];
        }
        if ($to instanceof User) {
            return [$to];
        }
        if (is_int($to)) {
            return array_filter([User::find($to)]);
        }

        return $to;
    }
}
