<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotificationPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'push_enabled',
        'email_enabled',
        'task_alerts',
        'deadline_alerts',
        'chat_alerts',
        'project_alerts',
    ];

    protected $casts = [
        'push_enabled'     => 'boolean',
        'email_enabled'    => 'boolean',
        'task_alerts'      => 'boolean',
        'deadline_alerts'  => 'boolean',
        'chat_alerts'      => 'boolean',
        'project_alerts'   => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Determine whether an alert type is enabled for this user.
     */
    public function isAlertEnabled(string $type): bool
    {
        if (in_array($type, ['task_overdue', 'task_due_soon', 'project_deadline_approaching', 'project_overdue'])) {
            return (bool) $this->deadline_alerts;
        }

        if (str_starts_with($type, 'task_')) {
            return (bool) $this->task_alerts;
        }

        if (str_starts_with($type, 'chat_')) {
            return (bool) $this->chat_alerts;
        }

        if (str_starts_with($type, 'project_')) {
            return (bool) $this->project_alerts;
        }

        return true;
    }
}
