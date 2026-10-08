<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class DocumentFolder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['roles' => 'array', 'shared_with_agents' => 'boolean', 'is_system' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('name');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'folder_id');
    }

    /** Roles allowed here: own setting, else nearest ancestor's, else every staff role. */
    public function effectiveRoles(?Collection $all = null): ?array
    {
        $all ??= self::all()->keyBy('id');
        for ($f = $this; $f; $f = $f->parent_id ? $all->get($f->parent_id) : null) {
            if ($f->roles) {
                return $f->roles;
            }
        }

        return null;
    }

    public function sharedWithAgents(?Collection $all = null): bool
    {
        $all ??= self::all()->keyBy('id');
        for ($f = $this; $f; $f = $f->parent_id ? $all->get($f->parent_id) : null) {
            if ($f->shared_with_agents) {
                return true;
            }
        }

        return false;
    }

    /** "Sales / Mandates" */
    public function path(?Collection $all = null): string
    {
        $all ??= self::all()->keyBy('id');
        $parts = [];
        for ($f = $this; $f; $f = $f->parent_id ? $all->get($f->parent_id) : null) {
            array_unshift($parts, $f->name);
        }

        return implode(' / ', $parts);
    }

    /**
     * Folder ids the user may open, plus those where an agent sees everyone's documents.
     *
     * @return array{visible: int[], shared: int[]}
     */
    public static function accessFor(User $user): array
    {
        $all = self::all()->keyBy('id');
        $role = $user->primaryRole();
        $visible = [];
        $shared = [];
        foreach ($all as $f) {
            $roles = $f->effectiveRoles($all);
            if ($role === 'admin' || $roles === null || in_array($role, $roles, true)) {
                $visible[] = $f->id;
                if ($f->sharedWithAgents($all)) {
                    $shared[] = $f->id;
                }
            }
        }

        return ['visible' => $visible, 'shared' => $shared];
    }
}
