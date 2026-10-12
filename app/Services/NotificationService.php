<?php

namespace App\Services;

use App\Events\Notifications\NotificationBroadcastEvent;
use App\Jobs\SendPushNotificationJob;
use App\Models\Notification;
use App\Models\NotificationDelivery;
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
     * real-time WebSockets, and queued mobile/desktop FCM push.
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
        $user = User::with(['notificationPreferences', 'company'])->find($userId);
        if (! $user) {
            return null;
        }

        // Always scope to the user's actual company
        $actualCompanyId = $user->company_id;

        // 1. Create persistent in-app notification record (ALWAYS created, separate from push preferences)
        $notification = Notification::create([
            'company_id' => $actualCompanyId,
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

            NotificationDelivery::create([
                'notification_id' => $notification->id,
                'user_id'         => $user->id,
                'channel'         => 'websocket',
                'status'          => 'sent',
            ]);
        } catch (\Throwable $e) {
            Log::debug('Notification broadcast skipped: ' . $e->getMessage());
        }

        // 3. Mobile / Desktop Push notification via FCM
        $this->handlePushDelivery($user, $notification, $type, $title, $message, $data);

        return $notification;
    }

    /**
     * Determine push eligibility and dispatch asynchronous queued push job.
     */
    protected function handlePushDelivery(
        User $user,
        Notification $notification,
        string $type,
        string $title,
        string $message,
        array $data
    ): void {
        $prefs = $user->notificationPreferences;
        $isMandatory = NotificationTypeRegistry::isMandatory($type);

        // Check quiet hours (mandatory alerts bypass quiet hours)
        $companyTz = $user->company?->timezone ?: 'Asia/Kolkata';
        if (! $isMandatory && $prefs && $prefs->isQuietHoursNow($companyTz)) {
            NotificationDelivery::create([
                'notification_id' => $notification->id,
                'user_id'         => $user->id,
                'channel'         => 'fcm',
                'status'          => 'skipped',
                'error'           => 'Suppressed by quiet hours',
            ]);
            return;
        }

        // Check alert preference opt-out (mandatory alerts bypass user opt-out)
        $allowPush = $isMandatory || ($prefs === null || ($prefs->push_enabled && $prefs->isAlertEnabled($type)));
        if (! $allowPush) {
            NotificationDelivery::create([
                'notification_id' => $notification->id,
                'user_id'         => $user->id,
                'channel'         => 'fcm',
                'status'          => 'skipped',
                'error'           => 'User opted out of alert category',
            ]);
            return;
        }

        // Lock-screen preview privacy protection
        $pushTitle = $title;
        $pushBody = $message;
        if ($prefs && $prefs->hide_previews) {
            if (str_starts_with($type, 'chat_')) {
                $pushBody = 'New message received';
            } else {
                $pushBody = 'You have a new update';
            }
        }

        $payloadData = array_merge($data, [
            'notification_id' => (string) $notification->id,
            'type'            => $type,
        ]);

        // Dispatch queued push notification job
        SendPushNotificationJob::dispatch(
            $user->id,
            $pushTitle,
            $pushBody,
            $payloadData,
            $notification->id
        );
    }

    /**
     * Dispatch notification to multiple users with batched queries to prevent N+1 overhead.
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
        $uniqueIds = array_values(array_filter(array_unique($userIds)));
        if (empty($uniqueIds)) {
            return [];
        }

        $users = User::with(['notificationPreferences', 'company'])
            ->whereIn('id', $uniqueIds)
            ->get();

        $notifications = [];
        foreach ($users as $user) {
            $notification = Notification::create([
                'company_id' => $user->company_id,
                'user_id'    => $user->id,
                'type'       => $type,
                'title'      => $title,
                'message'    => $message,
                'data'       => $data,
            ]);

            try {
                $unreadCount = Notification::where('user_id', $user->id)
                    ->whereNull('read_at')
                    ->count();

                NotificationBroadcastEvent::dispatch($notification, $unreadCount);
            } catch (\Throwable $e) {
                Log::debug('Notification broadcast skipped: ' . $e->getMessage());
            }

            $this->handlePushDelivery($user, $notification, $type, $title, $message, $data);

            $notifications[] = $notification;
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

        if (! $this->fcmService->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Push notifications are not configured on the server. Missing credentials or project ID.',
                'details' => [
                    'tokens_count' => count($tokens),
                    'sent'         => 0,
                    'failed'       => count($tokens),
                    'configured'   => false,
                ],
            ];
        }

        // 1. Create a persistent database notification & trigger WebSocket broadcast
        $notif = $this->notifyUser(
            $user->id,
            'test_push',
            'Test Notification',
            'Hello ' . $user->name . '! Push and in-app alerts are active and functioning on Capeonn.',
            [
                'type'      => 'test_push',
                'timestamp' => (string) now()->timestamp,
            ],
            $user->company_id
        );

        // 2. Perform direct push via FCM service to obtain real delivery status
        $res = $this->fcmService->sendToTokens(
            $tokens,
            'Test Notification',
            'Hello ' . $user->name . '! Push alerts are functioning on Capeonn.',
            [
                'type'            => 'test_push',
                'notification_id' => (string) ($notif?->id ?? 0),
            ]
        );

        $sentCount = $res['sent'] ?? 0;
        $failedCount = $res['failed'] ?? 0;

        if ($sentCount > 0) {
            return [
                'success' => true,
                'message' => "Test push dispatched to {$sentCount} device(s)." . ($failedCount > 0 ? " ({$failedCount} failed)" : ''),
                'details' => array_merge($res, [
                    'notification_id' => $notif?->id,
                    'tokens_count'    => count($tokens),
                ]),
            ];
        }

        return [
            'success' => false,
            'message' => 'Test push delivery failed for all registered devices.',
            'details' => array_merge($res, [
                'notification_id' => $notif?->id,
                'tokens_count'    => count($tokens),
            ]),
        ];
    }
}
