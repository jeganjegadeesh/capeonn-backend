<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /**
     * Store or update device push token for authenticated user (Phase 8 FCM ready).
     */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:500'],
            'platform' => ['nullable', 'string', 'in:android,ios,web'],
        ]);

        $deviceToken = DeviceToken::updateOrCreate(
            [
                'token' => $validated['token'],
            ],
            [
                'user_id' => $actor->id,
                'platform' => $validated['platform'] ?? 'android',
                'last_used_at' => now(),
            ]
        );

        return $this->success([
            'id' => $deviceToken->id,
            'token' => $deviceToken->token,
            'platform' => $deviceToken->platform,
        ], 'Device token registered successfully', 201);
    }

    /**
     * Remove device token (e.g. on logout).
     */
    public function destroy(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        DeviceToken::where('user_id', $actor->id)
            ->where('token', $validated['token'])
            ->delete();

        return $this->success(null, 'Device token unregistered successfully');
    }
}
