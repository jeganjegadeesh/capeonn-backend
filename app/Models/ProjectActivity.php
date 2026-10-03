<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class ProjectActivity extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'user_id',
        'action',
        'field',
        'old_value',
        'new_value',
        'reason',
        'description',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Enforce append-only audit trail
        static::updating(function () {
            throw new RuntimeException('Project activity logs are append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new RuntimeException('Project activity logs are append-only and cannot be deleted.');
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
