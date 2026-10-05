<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PLANNED = 'planned';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ON_HOLD = 'on_hold';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_CANCELLED = 'cancelled';

    // Backwards compatibility aliases
    public const STATUS_PLANNING = self::STATUS_PLANNED;
    public const STATUS_IN_PROGRESS = self::STATUS_ACTIVE;

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const HEALTH_ON_TRACK = 'on_track';
    public const HEALTH_DUE_SOON = 'due_soon';
    public const HEALTH_OVERDUE = 'overdue';
    public const HEALTH_COMPLETED = 'completed';

    /** Allowed status lifecycle transitions */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_PLANNED   => [self::STATUS_ACTIVE, self::STATUS_ON_HOLD, self::STATUS_CANCELLED],
        self::STATUS_ACTIVE    => [self::STATUS_ON_HOLD, self::STATUS_COMPLETED, self::STATUS_ARCHIVED, self::STATUS_CANCELLED],
        self::STATUS_ON_HOLD   => [self::STATUS_ACTIVE, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [self::STATUS_ACTIVE, self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED  => [self::STATUS_ACTIVE],
        self::STATUS_CANCELLED => [self::STATUS_ACTIVE],
    ];

    protected $fillable = [
        'company_id',
        'department_id',
        'manager_id',
        'name',
        'code',
        'description',
        'client_name',
        'status',
        'status_changed_by_id',
        'status_changed_at',
        'status_change_reason',
        'completion_requested_at',
        'completion_requested_by_id',
        'completion_request_notes',
        'priority',
        'team_lead_id',
        'created_by_id',
        'start_date',
        'deadline',
        'estimated_hours',
        'budget',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'deadline' => 'date',
            'estimated_hours' => 'decimal:2',
            'budget' => 'decimal:2',
            'status_changed_at' => 'datetime',
            'completion_requested_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function teamLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_lead_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by_id');
    }

    public function completionRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completion_requested_by_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot(['id', 'project_role', 'assigned_at', 'assigned_by_id'])
            ->withTimestamps();
    }

    public function projectMembers(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivity::class)->orderByDesc('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProjectFile::class)->orderByDesc('id');
    }

    public function conversation(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    /**
     * Dynamically computed progress based on completed tasks.
     * Phase 5 requirement: computed from tasks, null if no tasks exist.
     */
    public function getProgressAttribute(): ?int
    {
        $tasks = $this->relationLoaded('tasks') ? $this->tasks : $this->tasks()->get();
        $rootTasks = $tasks->whereNull('parent_task_id');
        $total = $rootTasks->count();
        if ($total === 0) {
            return null;
        }

        $completed = $rootTasks->where('status', Task::STATUS_COMPLETED)->count();

        return (int) round(($completed / $total) * 100);
    }

    /**
     * Aggregated task metrics for project dashboard and overview.
     * Counts root tasks only to avoid double-counting subtasks.
     */
    public function getTaskMetricsAttribute(): array
    {
        $tasks = $this->relationLoaded('tasks') ? $this->tasks : $this->tasks()->get();
        $rootTasks = $tasks->whereNull('parent_task_id');
        $total = $rootTasks->count();

        if ($total === 0) {
            return [
                'total_tasks' => 0,
                'completed_tasks' => 0,
                'in_progress_tasks' => 0,
                'review_tasks' => 0,
                'backlog_tasks' => 0,
                'overdue_tasks' => 0,
                'progress_percentage' => 0,
                'estimated_hours_total' => 0.0,
                'actual_hours_total' => 0.0,
            ];
        }

        $completed = $rootTasks->where('status', Task::STATUS_COMPLETED)->count();
        $inProgress = $rootTasks->where('status', Task::STATUS_IN_PROGRESS)->count();
        $review = $rootTasks->where('status', Task::STATUS_REVIEW)->count();
        $backlog = $rootTasks->whereIn('status', [Task::STATUS_BACKLOG, Task::STATUS_ASSIGNED])->count();
        $overdue = $rootTasks->filter(fn ($t) => $t->is_overdue)->count();

        return [
            'total_tasks' => $total,
            'completed_tasks' => $completed,
            'in_progress_tasks' => $inProgress,
            'review_tasks' => $review,
            'backlog_tasks' => $backlog,
            'overdue_tasks' => $overdue,
            'progress_percentage' => (int) round(($completed / $total) * 100),
            'estimated_hours_total' => round((float) $tasks->sum('estimated_hours'), 2),
            'actual_hours_total' => round((float) $tasks->sum('actual_hours'), 2),
        ];
    }

    /**
     * Checks if project accepts new work (tasks, time entries).
     * On Hold, Completed, Archived, and Cancelled projects block new tasks and time entries.
     */
    public function acceptsWork(): bool
    {
        return ! in_array($this->status, [
            self::STATUS_ON_HOLD,
            self::STATUS_COMPLETED,
            self::STATUS_ARCHIVED,
            self::STATUS_CANCELLED,
        ], true);
    }

    public function canAcceptTasks(): bool
    {
        return $this->acceptsWork();
    }

    public function canAcceptTimeEntries(): bool
    {
        return $this->acceptsWork();
    }

    public function getAcceptsWorkAttribute(): bool
    {
        return $this->acceptsWork();
    }

    public function getIsArchivedAttribute(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * Checks if a user is an assigned member in project_members.
     */
    public function isMember(int|User $user): bool
    {
        $userId = $user instanceof User ? $user->id : (int) $user;
        return $this->members()->where('users.id', $userId)->exists();
    }

    /**
     * Checks if a user is the assigned Team Lead.
     */
    public function isTeamLead(int|User $user): bool
    {
        $userId = $user instanceof User ? $user->id : (int) $user;
        return (int) $this->team_lead_id === $userId;
    }

    /**
     * Tasks are only assignable to project members (and the assigned team lead).
     */
    public function canAssignTaskTo(int|User $user): bool
    {
        $userId = $user instanceof User ? $user->id : (int) $user;
        return $this->isMember($userId) || $this->isTeamLead($userId);
    }

    public function getIsOverdueAttribute(): bool
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_ARCHIVED, self::STATUS_CANCELLED], true)) {
            return false;
        }

        if (! $this->deadline) {
            return false;
        }

        return $this->deadline->isPast();
    }

    public function getDaysRemainingAttribute(): ?int
    {
        if (! $this->deadline) {
            return null;
        }

        return (int) Carbon::today()->diffInDays($this->deadline, false);
    }

    public function getHealthLabelAttribute(): string
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_ARCHIVED, self::STATUS_CANCELLED], true)) {
            return self::HEALTH_COMPLETED;
        }

        if (! $this->deadline) {
            return self::HEALTH_ON_TRACK;
        }

        if ($this->is_overdue) {
            return self::HEALTH_OVERDUE;
        }

        $days = $this->days_remaining;
        if ($days !== null && $days <= 7) {
            return self::HEALTH_DUE_SOON;
        }

        return self::HEALTH_ON_TRACK;
    }

    public function getTotalTeamCountAttribute(): int
    {
        $members = $this->relationLoaded('members') ? $this->members->count() : $this->members()->count();
        return $members + ($this->team_lead_id ? 1 : 0);
    }

    /** Log an append-only activity to the audit trail */
    public function recordActivity(
        string $action,
        string $description,
        ?int $userId = null,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?string $reason = null,
        ?array $metadata = null,
        ?int $taskId = null,
    ): ProjectActivity {
        return $this->activities()->create([
            'user_id'     => $userId,
            'task_id'     => $taskId,
            'action'      => $action,
            'field'       => $field,
            'old_value'   => $oldValue,
            'new_value'   => $newValue,
            'reason'      => $reason,
            'description' => $description,
            'metadata'    => $metadata,
            'created_at'  => now(),
        ]);
    }
}
