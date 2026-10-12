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
        'quiet_hours_enabled',
        'quiet_hours_start',
        'quiet_hours_end',
        'hide_previews',
        'chat_mentions_only',
    ];

    protected $casts = [
        'push_enabled'        => 'boolean',
        'email_enabled'       => 'boolean',
        'task_alerts'         => 'boolean',
        'deadline_alerts'     => 'boolean',
        'chat_alerts'         => 'boolean',
        'project_alerts'      => 'boolean',
        'quiet_hours_enabled' => 'boolean',
        'hide_previews'       => 'boolean',
        'chat_mentions_only'  => 'boolean',
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
        if (\App\Services\NotificationTypeRegistry::isMandatory($type)) {
            return true;
        }

        $category = \App\Services\NotificationTypeRegistry::getCategoryForType($type);

        if ($category === 'deadlines') {
            return (bool) $this->deadline_alerts;
        }

        if ($category === 'tasks') {
            return (bool) $this->task_alerts;
        }

        if ($category === 'chat') {
            if ($this->chat_mentions_only && $type === 'chat_message') {
                return false;
            }
            return (bool) $this->chat_alerts;
        }

        if ($category === 'projects') {
            return (bool) $this->project_alerts;
        }

        return true;
    }

    /**
     * Check if currently in quiet hours.
     */
    public function isQuietHoursNow(?string $timezone = 'Asia/Kolkata'): bool
    {
        if (! $this->quiet_hours_enabled || ! $this->quiet_hours_start || ! $this->quiet_hours_end) {
            return false;
        }

        $tz = $timezone ?: 'Asia/Kolkata';
        $now = now()->setTimezone($tz);
        $currentTime = $now->format('H:i');

        if ($this->quiet_hours_start <= $this->quiet_hours_end) {
            return $currentTime >= $this->quiet_hours_start && $currentTime <= $this->quiet_hours_end;
        }

        // Crosses midnight (e.g. 22:00 to 07:00)
        return $currentTime >= $this->quiet_hours_start || $currentTime <= $this->quiet_hours_end;
    }
}
