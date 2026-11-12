<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspaceId = $this->workspace()->id;
        $notifications = $request->user()->notifications()->where('data->workspace_id', $workspaceId)->limit(30)->get();

        return response()->json([
            'data' => $notifications->map(fn ($notification) => [
                'id' => $notification->id,
                'data' => $notification->data,
                'read_at' => $notification->read_at,
                'created_at' => $notification->created_at,
            ]),
            'unread' => $request->user()->unreadNotifications()->where('data->workspace_id', $workspaceId)->count(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->where('data->workspace_id', $this->workspace()->id)->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
