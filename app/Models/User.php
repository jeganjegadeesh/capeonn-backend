<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $attributes = [
        'is_active' => true,
        'is_attendance_applicable' => true,
    ];

    /**
     * Only ever fill these from validated request data, never from $request->all().
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'company_id',
        'department_id',
        'designation_id',
        'role_id',
        'reports_to_id',
        'employee_code',
        'joined_on',
        'is_active',
        'is_attendance_applicable',
        'salary',
        'last_login_at',
        'dob',
        'gender',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'employment_type',
        'probation_end_date',
        'skills',
        'certifications',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'joined_on' => 'date',
            'is_active' => 'boolean',
            'is_attendance_applicable' => 'boolean',
            'salary' => 'decimal:2',
            'last_login_at' => 'datetime',
            'dob' => 'date',
            'probation_end_date' => 'date',
            'skills' => 'array',
            'certifications' => 'array',
        ];
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** Direct superior (Employee -> TL, TL -> Manager). */
    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reports_to_id');
    }

    /** People who report directly to this user. For a Team Lead, this is "own team". */
    public function directReports(): HasMany
    {
        return $this->hasMany(User::class, 'reports_to_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(EmployeeHistory::class)->orderByDesc('effective_date')->orderByDesc('id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class)->orderByDesc('date');
    }

    public function attendanceRegularizations(): HasMany
    {
        return $this->hasMany(AttendanceRegularization::class)->orderByDesc('created_at');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class)->orderByDesc('start_date');
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function ledProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'team_lead_id');
    }

    public function createdProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'created_by_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')
            ->withPivot(['id', 'project_role', 'assigned_at', 'assigned_by_id'])
            ->withTimestamps();
    }

    /**
     * The user's permissions as [slug => scope], e.g. ['employees.view' => 'department'].
     * Load `role.permissions` first to avoid extra queries.
     *
     * @return array<string, string>
     */
    public function permissionScopes(): array
    {
        if (! $this->role) {
            return [];
        }

        return $this->role->permissions
            ->mapWithKeys(fn ($permission) => [$permission->slug => $permission->pivot->scope])
            ->all();
    }

    /** Scope this user has for a permission (all/department/team/assigned/self), or null if none. */
    public function scopeFor(string $permission): ?string
    {
        return $this->permissionScopes()[$permission] ?? null;
    }

    public function hasPermission(string $permission): bool
    {
        return $this->scopeFor($permission) !== null;
    }

    public function hasRole(string ...$slugs): bool
    {
        return $this->role !== null && in_array($this->role->slug, $slugs, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN, 'admin');
    }

    public function isHR(): bool
    {
        return $this->hasRole(Role::HR);
    }
}