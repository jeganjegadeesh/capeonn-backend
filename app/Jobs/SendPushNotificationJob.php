<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Services\FcmNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $userId,
        public string $title,
        public string $message,
        public array $payloadData = [],
        public ?int $notificationId = null
    ) {
    }

    public function handle(FcmNotificationService $fcmService): void
    {
        try {
            $result = $fcmService->sendToUser(
                $this->userId,
                $this->title,
                $this->message,
                $this->payloadData
            );

            $status = 'skipped';
            if (($result['sent'] ?? 0) > 0) {
                $status = 'sent';
            } elseif (($result['failed'] ?? 0) > 0) {
                $status = 'failed';
            }

            NotificationDelivery::create([
                'notification_id' => $this->notificationId,
                'user_id'         => $this->userId,
                'channel'         => 'fcm',
                'status'          => $status,
                'error'           => $result['error'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('SendPushNotificationJob failed: ' . $e->getMessage(), [
                'user_id'         => $this->userId,
                'notification_id' => $this->notificationId,
            ]);

            try {
                NotificationDelivery::create([
                    'notification_id' => $this->notificationId,
                    'user_id'         => $this->userId,
                    'channel'         => 'fcm',
                    'status'          => 'failed',
                    'error'           => $e->getMessage(),
                ]);
            } catch (\Throwable) {
            }
        }
    }
}
