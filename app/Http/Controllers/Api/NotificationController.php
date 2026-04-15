<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'type' => class_basename($n->type),
                'data' => $n->data,
                'read_at' => $n->read_at,
                'created_at' => $n->created_at,
            ]);

        $unreadCount = $request->user()->unreadNotifications()->count();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $updated = $request->user()->notifications()->where('id', $id)->update(['read_at' => now()]);

        if ($updated === 0) {
            abort(404, 'Notification not found.');
        }

        return response()->json(['message' => 'Marked as read.']);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All marked as read.']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $deleted = $request->user()->notifications()->where('id', $id)->delete();

        if ($deleted === 0) {
            abort(404, 'Notification not found.');
        }

        return response()->json(['message' => 'Notification deleted.']);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $request->user()->notifications()->delete();

        return response()->json(['message' => 'All notifications deleted.']);
    }
}
