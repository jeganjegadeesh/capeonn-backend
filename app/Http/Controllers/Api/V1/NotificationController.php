<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Get paginated notifications list for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        $query = Notification::where('user_id', $actor->id)
            ->orderBy('created_at', 'desc');

        if ($request->query('unread_only')) {
            $query->whereNull('read_at');
        }

        $perPage = min((int) $request->query('per_page', 20), 50);
        $paginator = $query->paginate($perPage);

        $unreadCount = Notification::where('user_id', $actor->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'success' => true,
            'message' => 'Notifications retrieved successfully',
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    /**
     * Get unread notification count badge for the authenticated user.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $actor = $request->user();

        $count = Notification::where('user_id', $actor->id)
            ->whereNull('read_at')
            ->count();

        return $this->success(['unread_count' => $count], 'Unread count retrieved');
    }

    /**
     * Mark a specific notification as read.
     */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $notification = Notification::where('user_id', $actor->id)->findOrFail($id);
        $notification->markAsRead();

        return $this->success($notification, 'Notification marked as read');
    }

    /**
     * Mark all unread notifications as read for the authenticated user.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $actor = $request->user();

        Notification::where('user_id', $actor->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->success(null, 'All notifications marked as read');
    }

    /**
     * Delete a notification.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $notification = Notification::where('user_id', $actor->id)->findOrFail($id);
        $notification->delete();

        return $this->success(null, 'Notification deleted');
    }
}
