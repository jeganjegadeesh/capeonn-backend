<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\Chat\UserPresenceChangedEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    /**
     * Send heartbeat to register the user as active and online.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->update(['last_seen_at' => now()]);

        // Broadcast real-time presence change to company channel
        broadcast(new UserPresenceChangedEvent($user, isOnline: true))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Heartbeat acknowledged',
            'data' => [
                'user_id' => $user->id,
                'is_online' => true,
                'last_seen_at' => $user->last_seen_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Explicitly mark user as offline (e.g. app pause, logout).
     */
    public function offline(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Mark last seen in past beyond the active threshold
        $user->update(['last_seen_at' => now()->subMinutes(5)]);

        // Broadcast offline status
        broadcast(new UserPresenceChangedEvent($user, isOnline: false))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'User marked offline',
            'data' => [
                'user_id' => $user->id,
                'is_online' => false,
                'last_seen_at' => $user->last_seen_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Query presence status for a set of users or active colleagues in the company.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = User::where('company_id', $user->company_id)
            ->where('is_active', true);

        if ($idsParam = $request->query('ids')) {
            $ids = array_filter(array_map('intval', explode(',', $idsParam)));
            if (! empty($ids)) {
                $query->whereIn('id', $ids);
            }
        }

        $users = $query->select(['id', 'name', 'last_seen_at'])->get();

        $data = $users->map(fn (User $u) => [
            'user_id' => $u->id,
            'name' => $u->name,
            'is_online' => $u->isOnline(),
            'last_seen_at' => $u->last_seen_at?->toISOString(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
