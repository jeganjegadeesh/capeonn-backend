<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_BACKLOG = 'backlog';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_REVIEW = 'review';
    public const STATUS_CHANGES_REQUIRED = 'changes_required';
    public const STATUS_COMPLETED = 'completed';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const VARIANCE_EARLY = 'early';
    public const VARIANCE_ON_TIME = 'on_time';
    public const VARIANCE_OVER_TIME = 'over_time';
    public const VARIANCE_NONE = 'none';

    public const DEADLINE_EARLY = 'completed_early';
    public const DEADLINE_ON_TIME = 'completed_on_time';
    public const DEADLINE_LATE = 'completed_late';
    public const DEADLINE_ON_TRACK = 'on_track';
    public const DEADLINE_OVERDUE = 'overdue';

    /** Allowed status lifecycle transitions */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_BACKLOG          => [self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS],
        self::STATUS_ASSIGNED         => [self::STATUS_IN_PROGRESS, self::STATUS_BACKLOG],
        self::STATUS_IN_PROGRESS      => [self::STATUS_REVIEW, self::STATUS_ASSIGNED],
        self::STATUS_REVIEW           => [self::STATUS_CHANGES_REQUIRED, self::STATUS_COMPLETED],
        self::STATUS_CHANGES_REQUIRED => [self::STATUS_IN_PROGRESS],
        self::STATUS_COMPLETED        => [self::STATUS_IN_PROGRESS], // Reopen
    ];

    protected $fillable = [
        'company_id',
        'project_id',
        'parent_task_id',
        'title',
        'description',
        'status',
        'priority',
        'position',
        'assigned_to_id',
        'created_by_id',
        'due_date',
        'estimated_hours',
        'actual_hours',
        'started_at',
        'completed_at',
        'submitted_by_id',
        'submitted_at',
        'reviewer_id',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'estimated_hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class)->orderByDesc('id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivity::class)->orderByDesc('id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProjectFile::class)->orderByDesc('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->whereNull('parent_id')->orderBy('id', 'asc');
    }

    public function allComments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('id', 'asc');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(TaskReview::class)->orderBy('round_number', 'asc');
    }

    public function latestReview(): HasOne
    {
        return $this->hasOne(TaskReview::class)->latestOfMany('round_number');
    }

    public function getIsOverdueAttribute(): bool
    {
        if (in_array($this->status, [self::STATUS_COMPLETED], true)) {
            return false;
        }

        if (! $this->due_date) {
            return false;
        }

        return $this->due_date->isPast();
    }

    public function getDaysRemainingAttribute(): ?int
    {
        if (! $this->due_date) {
            return null;
        }

        return (int) Carbon::today()->diffInDays($this->due_date, false);
    }

    public function getTimeVarianceAttribute(): string
    {
        // Only classify completed tasks as early, on_time, or over_time
        if ($this->status !== self::STATUS_COMPLETED) {
            return self::VARIANCE_NONE;
        }

        if ($this->estimated_hours === null || (float) $this->estimated_hours <= 0) {
            return self::VARIANCE_NONE;
        }

        $est = (float) $this->estimated_hours;
        $act = (float) ($this->actual_hours ?? 0);

        // Configured tolerance: +/- 10% (0.90 to 1.10)
        $lowerBound = $est * 0.90;
        $upperBound = $est * 1.10;

        if ($act < $lowerBound) {
            return self::VARIANCE_EARLY;
        }

        if ($act > $upperBound) {
            return self::VARIANCE_OVER_TIME;
        }

        return self::VARIANCE_ON_TIME;
    }

    public function getDeadlineVarianceAttribute(): string
    {
        if ($this->status === self::STATUS_COMPLETED) {
            if ($this->due_date && $this->completed_at) {
                $completedDate = $this->completed_at->toDateString();
                $dueDate = $this->due_date->toDateString();

                if ($completedDate < $dueDate) {
                    return self::DEADLINE_EARLY;
                }
                if ($completedDate > $dueDate) {
                    return self::DEADLINE_LATE;
                }
                return self::DEADLINE_ON_TIME;
            }
            return self::DEADLINE_ON_TIME;
        }

        if ($this->is_overdue) {
            return self::DEADLINE_OVERDUE;
        }

        return self::DEADLINE_ON_TRACK;
    }

    public function activeTimerFor(int $userId): ?TimeEntry
    {
        return $this->timeEntries()->running()->where('user_id', $userId)->first();
    }

    public function recalculateActualHours(): void
    {
        $totalSeconds = (int) $this->timeEntries()->completed()->sum('duration_seconds');
        $this->update([
            'actual_hours' => round($totalSeconds / 3600, 2),
        ]);
    }
}
