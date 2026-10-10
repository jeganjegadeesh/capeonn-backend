<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserNotificationPreference;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferencesController extends Controller
{
    /**
     * Get notification preferences for the authenticated user.
     */
    public function show(Request $request): JsonResponse
    {
        $actor = $request->user();

        $preferences = UserNotificationPreference::firstOrCreate(
            ['user_id' => $actor->id],
            [
                'push_enabled'     => true,
                'email_enabled'    => true,
                'task_alerts'      => true,
                'deadline_alerts'  => true,
                'chat_alerts'      => true,
                'project_alerts'   => true,
            ]
        );

        return $this->success($preferences, 'Notification preferences retrieved successfully');
    }

    /**
     * Update notification preferences for the authenticated user.
     */
    public function update(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'push_enabled'     => ['nullable', 'boolean'],
            'email_enabled'    => ['nullable', 'boolean'],
            'task_alerts'      => ['nullable', 'boolean'],
            'deadline_alerts'  => ['nullable', 'boolean'],
            'chat_alerts'      => ['nullable', 'boolean'],
            'project_alerts'   => ['nullable', 'boolean'],
        ]);

        $preferences = UserNotificationPreference::updateOrCreate(
            ['user_id' => $actor->id],
            array_filter($validated, fn ($val) => $val !== null)
        );

        return $this->success($preferences, 'Notification preferences updated successfully');
    }

    /**
     * Send a test push notification to verify device token configuration.
     */
    public function testPush(Request $request, NotificationService $notificationService): JsonResponse
    {
        $actor = $request->user();
        $res = $notificationService->sendTestPush($actor);

        if (! $res['success']) {
            return $this->error($res['message'], 422, $res['details']);
        }

        return $this->success($res['details'], $res['message']);
    }
}
