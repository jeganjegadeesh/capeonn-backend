<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TimeEntry extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'project_id',
        'task_id',
        'user_id',
        'started_at',
        'ended_at',
        'duration_seconds',
        'description',
        'is_manual',
        'is_auto_stopped',
        'paused_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'paused_at' => 'datetime',
            'duration_seconds' => 'integer',
            'is_manual' => 'boolean',
            'is_auto_stopped' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('ended_at');
    }

    public function getIsRunningAttribute(): bool
    {
        return $this->ended_at === null;
    }

    public function getIsPausedAttribute(): bool
    {
        return $this->paused_at !== null && $this->ended_at === null;
    }

    public function getDurationHoursAttribute(): float
    {
        if ($this->is_running) {
            $effectiveEnd = $this->is_paused ? $this->paused_at : now();
            $seconds = (int) $this->started_at->diffInSeconds($effectiveEnd);
            return round($seconds / 3600, 2);
        }

        return round($this->duration_seconds / 3600, 2);
    }

    /**
     * Stop a running timer, calculate duration and update task's actual hours.
     */
    public function stop(?Carbon $endedAt = null, bool $isAutoStopped = false): void
    {
        if (! $this->is_running) {
            return;
        }

        $endTime = $endedAt ?? now();
        $this->ended_at = $endTime;
        $this->is_auto_stopped = $isAutoStopped;
        $this->duration_seconds = max(0, (int) $this->started_at->diffInSeconds($endTime));
        $this->save();

        // Refresh task actual_hours from all completed time entries
        if ($this->task) {
            $totalSeconds = $this->task->timeEntries()->completed()->sum('duration_seconds');
            $this->task->update([
                'actual_hours' => round($totalSeconds / 3600, 2),
            ]);
        }
    }

    public function pause(?Carbon $pausedAt = null): void
    {
        if (! $this->is_running || $this->is_paused) {
            return;
        }

        $this->paused_at = $pausedAt ?? now();
        $this->save();
    }

    public function resume(): void
    {
        if (! $this->is_paused) {
            return;
        }

        $this->paused_at = null;
        $this->save();
    }
}
