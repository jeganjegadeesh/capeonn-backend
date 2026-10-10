<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /**
     * List registered devices for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $devices = DeviceToken::where('user_id', $actor->id)
            ->orderBy('last_used_at', 'desc')
            ->get(['id', 'platform', 'device_name', 'last_used_at', 'created_at']);

        return $this->success($devices, 'Registered devices retrieved successfully');
    }

    /**
     * Store or update device push token for authenticated user (Phase 8 FCM).
     */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:500'],
            'platform' => ['nullable', 'string', 'in:android,ios,web,windows'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $deviceToken = DeviceToken::updateOrCreate(
            [
                'token' => $validated['token'],
            ],
            [
                'user_id' => $actor->id,
                'platform' => $validated['platform'] ?? 'android',
                'device_name' => $validated['device_name'] ?? null,
                'last_used_at' => now(),
            ]
        );

        return $this->success([
            'id' => $deviceToken->id,
            'token' => $deviceToken->token,
            'platform' => $deviceToken->platform,
            'device_name' => $deviceToken->device_name,
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
