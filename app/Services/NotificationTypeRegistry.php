<?php

namespace App\Services;

class NotificationTypeRegistry
{
    /**
     * Mandatory notification types that cannot be disabled via preferences.
     */
    public const MANDATORY_TYPES = [
        'task_assigned',
        'task_review',
        'task_changes_requested',
        'task_completed',
        'task_overdue',
    ];

    /**
     * All recognized notification types in the system.
     */
    public const ALL_TYPES = [
        // Tasks
        'task_assigned',
        'task_reassigned',
        'task_review',
        'task_changes_requested',
        'task_completed',
        'task_reopened',
        'task_overdue',
        'task_due_soon',
        'task_comment',

        // Projects
        'project_lead_assigned',
        'project_completion_requested',
        'project_deadline_approaching',
        'project_overdue',
        'project_member_added',
        'project_member_removed',
        'project_status_changed',
        'project_deadline_changed',
        'project_file_uploaded',

        // Chat
        'chat_message',
        'chat_mention',

        // System
        'test_push',
    ];

    /**
     * Check if a notification type is mandatory.
     */
    public static function isMandatory(string $type): bool
    {
        return in_array($type, self::MANDATORY_TYPES, true);
    }

    /**
     * Map notification type to preference category.
     */
    public static function getCategoryForType(string $type): string
    {
        if (in_array($type, [
            'task_overdue',
            'task_due_soon',
            'project_deadline_approaching',
            'project_overdue',
        ], true)) {
            return 'deadlines';
        }

        if (str_starts_with($type, 'task_')) {
            return 'tasks';
        }

        if (str_starts_with($type, 'project_')) {
            return 'projects';
        }

        if (str_starts_with($type, 'chat_')) {
            return 'chat';
        }

        if ($type === 'test_push') {
            return 'system';
        }

        return 'general';
    }
}
