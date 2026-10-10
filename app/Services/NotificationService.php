<?php

namespace App\Services;

use App\Events\Notifications\NotificationBroadcastEvent;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(
        protected FcmNotificationService $fcmService
    ) {
    }

    /**
     * Dispatch a notification to a specific user across in-app inbox,
     * real-time WebSockets, and mobile/desktop FCM push.
     *
     * @param  array<string, mixed>  $data
     */
    public function notifyUser(
        int $userId,
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?int $companyId = null
    ): ?Notification {
        $user = User::with('notificationPreferences')->find($userId);
        if (! $user) {
            return null;
        }

        $prefs = $user->notificationPreferences;

        // Check if the user has opted out of this alert category
        if ($prefs && ! $prefs->isAlertEnabled($type)) {
            return null;
        }

        $companyId = $companyId ?? $user->company_id;

        // 1. Create persistent in-app notification record
        $notification = Notification::create([
            'company_id' => $companyId,
            'user_id'    => $user->id,
            'type'       => $type,
            'title'      => $title,
            'message'    => $message,
            'data'       => $data,
        ]);

        // 2. Real-time WebSocket delivery
        try {
            $unreadCount = Notification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->count();

            NotificationBroadcastEvent::dispatch($notification, $unreadCount);
        } catch (\Throwable $e) {
            Log::debug('Notification broadcast skipped: ' . $e->getMessage());
        }

        // 3. Mobile / Desktop Push notification via FCM
        $allowPush = ($prefs === null || $prefs->push_enabled);
        if ($allowPush) {
            try {
                $payloadData = array_merge($data, [
                    'notification_id' => (string) $notification->id,
                    'type'            => $type,
                ]);

                $this->fcmService->sendToUser(
                    $user->id,
                    $title,
                    $message,
                    $payloadData
                );
            } catch (\Throwable $e) {
                Log::warning('FCM push delivery failed: ' . $e->getMessage());
            }
        }

        return $notification;
    }

    /**
     * Dispatch notification to multiple users.
     *
     * @param  array<int>  $userIds
     * @param  array<string, mixed>  $data
     * @return array<Notification>
     */
    public function notifyUsers(
        array $userIds,
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?int $companyId = null
    ): array {
        $notifications = [];
        $uniqueIds = array_filter(array_unique($userIds));

        foreach ($uniqueIds as $userId) {
            $n = $this->notifyUser((int) $userId, $type, $title, $message, $data, $companyId);
            if ($n) {
                $notifications[] = $n;
            }
        }

        return $notifications;
    }

    /**
     * Send an immediate test push to verify FCM setup.
     *
     * @return array{success: bool, message: string, details: array}
     */
    public function sendTestPush(User $user): array
    {
        $tokens = $user->deviceTokens()->pluck('token')->all();
        if (empty($tokens)) {
            return [
                'success' => false,
                'message' => 'No registered device tokens found for this account. Please log in on a supported device first.',
                'details' => ['tokens_count' => 0],
            ];
        }

        $result = $this->fcmService->sendToTokens(
            $tokens,
            'Test Push Notification',
            'Hello ' . $user->name . '! Push notifications are configured and functioning on Capeonn.',
            [
                'type' => 'test_push',
                'timestamp' => (string) now()->timestamp,
            ]
        );

        return [
            'success' => true,
            'message' => "Test push dispatched to {$result['sent']} device(s).",
            'details' => $result,
        ];
    }
}
