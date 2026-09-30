<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    public const ADMIN = 'admin';
    public const MANAGER = 'manager';
    public const TEAM_LEAD = 'team_lead';
    public const EMPLOYEE = 'employee';

    protected $fillable = ['name', 'slug', 'level', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'level' => 'integer'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission')->withPivot('scope');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
