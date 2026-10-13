<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
   
    public function index(Request $request)
    {
        $query = AppNotification::where('user_id', $request->user()->id);

        if ($request->has('is_read')) {
            $query->where('is_read', $request->boolean('is_read'));
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        return $query->latest()->get();
    }

    public function show(Request $request, AppNotification $notification)
    {
        $this->authorizeOwnership($request, $notification);

        return $notification;
    }

    public function unreadCount(Request $request)
    {
        return response()->json([
            'unread_count' => AppNotification::where('user_id', $request->user()->id)
                ->where('is_read', false)
                ->count(),
        ]);
    }

    public function markAsRead(Request $request, AppNotification $notification)
    {
        $this->authorizeOwnership($request, $notification);

        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return response()->json($notification);
    }

    public function markAllAsRead(Request $request)
    {
        AppNotification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'تم تعليم كل الإشعارات كمقروءة']);
    }

    public function destroy(Request $request, AppNotification $notification)
    {
        $this->authorizeOwnership($request, $notification);

        $notification->delete();

        return response()->json(['message' => 'تم حذف الإشعار']);
    }

    private function authorizeOwnership(Request $request, AppNotification $notification): void
    {
        if ($notification->user_id !== $request->user()->id) {
            abort(response()->json(['message' => 'ما عندك صلاحية توصل لهاد الإشعار'], 403));
        }
    }
}
