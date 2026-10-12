<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmNotificationService
{
    protected ?string $projectId;
    protected ?string $credentialsPath;

    public function __construct()
    {
        $this->projectId = config('services.fcm.project_id');
        $this->credentialsPath = config('services.fcm.credentials_path');
    }

    /**
     * Check if FCM service account and project ID are properly configured on the server.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->projectId)
            && ! empty($this->credentialsPath)
            && file_exists($this->credentialsPath);
    }

    /**
     * Send push notification to a specific list of device tokens using FCM HTTP v1.
     *
     * @param  array<string>  $tokens
     * @param  array<string, mixed>  $data
     * @return array{sent: int, failed: int, pruned: int, configured: bool, error?: string}
     */
    public function sendToTokens(array $tokens, string $title, string $message, array $data = []): array
    {
        $tokens = array_values(array_filter(array_unique($tokens), function ($token) {
            return is_string($token) && ! str_starts_with($token, 'device_') && ! str_starts_with($token, 'win_device_') && strlen($token) > 20;
        }));

        if (empty($tokens)) {
            return ['sent' => 0, 'failed' => 0, 'pruned' => 0, 'configured' => $this->isConfigured()];
        }

        if (! $this->isConfigured()) {
            Log::warning('FCM Push Notification: Service is not configured. Missing credentials or project ID.', [
                'has_project_id'   => ! empty($this->projectId),
                'credentials_path' => $this->credentialsPath,
            ]);

            return [
                'sent'        => 0,
                'failed'      => count($tokens),
                'pruned'      => 0,
                'configured'  => false,
                'error'       => 'Push notifications are not configured on the server.',
            ];
        }

        // Ensure all data values are stringified for FCM data payload compatibility
        $formattedData = [];
        foreach ($data as $k => $v) {
            $formattedData[(string) $k] = is_scalar($v) ? (string) $v : json_encode($v);
        }
        $formattedData['click_action'] = 'FLUTTER_NOTIFICATION_CLICK';

        return $this->sendViaHttpV1($tokens, $title, $message, $formattedData);
    }

    /**
     * Send to all registered device tokens of a user.
     */
    public function sendToUser(int $userId, string $title, string $message, array $data = []): array
    {
        $tokens = DeviceToken::where('user_id', $userId)->pluck('token')->all();
        if (empty($tokens)) {
            return ['sent' => 0, 'failed' => 0, 'pruned' => 0, 'configured' => $this->isConfigured()];
        }

        return $this->sendToTokens($tokens, $title, $message, $data);
    }

    /**
     * Send via FCM HTTP v1 API.
     */
    protected function sendViaHttpV1(array $tokens, string $title, string $message, array $data): array
    {
        $accessToken = $this->getOAuthAccessToken();
        if (! $accessToken) {
            Log::warning('FCM HTTP v1: Unable to obtain OAuth access token from credentials.');
            return [
                'sent'       => 0,
                'failed'     => count($tokens),
                'pruned'     => 0,
                'configured' => true,
                'error'      => 'Failed to obtain Google OAuth access token',
            ];
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
        $sent = 0;
        $failed = 0;
        $pruned = 0;

        foreach ($tokens as $token) {
            try {
                $payload = [
                    'message' => [
                        'token'        => $token,
                        'notification' => [
                            'title' => $title,
                            'body'  => $message,
                        ],
                        'data'         => $data,
                        'android'      => [
                            'priority'     => 'HIGH',
                            'notification' => [
                                'sound'                 => 'default',
                                'channel_id'            => 'capeonn_high_importance_channel',
                                'notification_priority' => 'PRIORITY_HIGH',
                            ],
                        ],
                        'apns'         => [
                            'payload' => [
                                'aps' => [
                                    'sound' => 'default',
                                    'badge' => 1,
                                ],
                            ],
                        ],
                    ],
                ];

                $response = Http::withToken($accessToken)
                    ->timeout(10)
                    ->post($url, $payload);

                if ($response->successful()) {
                    $sent++;
                } else {
                    $failed++;
                    $resJson = $response->json();
                    $errorCode = $resJson['error']['details'][0]['errorCode'] ?? $resJson['error']['status'] ?? null;
                    if (in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'], true)) {
                        $this->pruneToken($token);
                        $pruned++;
                    }
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error("FCM v1 send error for token: {$e->getMessage()}");
            }
        }

        return [
            'sent'       => $sent,
            'failed'     => $failed,
            'pruned'     => $pruned,
            'configured' => true,
        ];
    }

    /**
     * Delete an invalid or unregistered token from the database.
     */
    public function pruneToken(string $token): void
    {
        try {
            DeviceToken::where('token', $token)->delete();
            Log::info('Pruned unregistered FCM token: ' . substr($token, 0, 16) . '...');
        } catch (\Throwable $e) {
            Log::warning('Failed to prune token: ' . $e->getMessage());
        }
    }

    /**
     * Obtain OAuth access token from service account JSON, cached for 50 minutes.
     */
    public function getOAuthAccessToken(): ?string
    {
        if (! $this->credentialsPath || ! file_exists($this->credentialsPath)) {
            return null;
        }

        return Cache::remember('fcm_v1_oauth_access_token', now()->addMinutes(50), function () {
            try {
                $json = json_decode(file_get_contents($this->credentialsPath), true);
                if (! is_array($json) || empty($json['private_key']) || empty($json['client_email'])) {
                    return null;
                }

                // Standard JWT creation for Google OAuth2
                $now = time();
                $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
                $claims = json_encode([
                    'iss'   => $json['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud'   => 'https://oauth2.googleapis.com/token',
                    'iat'   => $now,
                    'exp'   => $now + 3600,
                ]);

                $b64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
                $b64Claims = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($claims));
                $dataToSign = "{$b64Header}.{$b64Claims}";

                $signature = '';
                if (! openssl_sign($dataToSign, $signature, $json['private_key'], OPENSSL_ALGO_SHA256)) {
                    return null;
                }

                $b64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
                $jwt = "{$dataToSign}.{$b64Signature}";

                $res = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ]);

                return $res->json()['access_token'] ?? null;
            } catch (\Throwable $e) {
                Log::error('FCM OAuth2 generation error: ' . $e->getMessage());
                return null;
            }
        });
    }
}
