<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $guarded = ['id'];

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('order');
    }

    public function completions(): HasMany
    {
        return $this->hasMany(CourseCompletion::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'course_completions')
            ->withPivot('status', 'completed_at')
            ->withTimestamps();
    }

    public function durationLabel(): string
    {
        $m = $this->duration_minutes;
        if ($m < 60) {
            return $m.' min';
        }
        $h = round($m / 60, 1);

        return ($h == (int) $h ? (int) $h : $h).' hrs';
    }
}
