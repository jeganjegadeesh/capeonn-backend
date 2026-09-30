<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /**
     * How far a granted permission reaches:
     *  all        every record in the company
     *  department records in the user's own department
     *  team       the user's downline (everyone below them via reports_to_id)
     *  assigned   only records assigned to the user (projects, tasks)
     *  self       only the user's own record
     */
    public const SCOPE_ALL = 'all';
    public const SCOPE_DEPARTMENT = 'department';
    public const SCOPE_TEAM = 'team';
    public const SCOPE_ASSIGNED = 'assigned';
    public const SCOPE_SELF = 'self';

    protected $fillable = ['slug', 'module', 'description'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission')->withPivot('scope');
    }
}