<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\UseCases\Notification\IndexAction;
use App\UseCases\Notification\MarkAllAsReadAction;
use App\UseCases\Notification\MarkAsReadAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * ログインユーザーの通知一覧をJSONで取得する。
     */
    public function index(Request $request, IndexAction $action): JsonResponse
    {
        $data = $action(
            user: $request->user(),
            tab: $request->query('tab', 'all'),
        );

        $notifications = $data['notifications']->getCollection()
            ->map(function ($notification): array {
                $url = $notification->data['url'] ?? null;

                return [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? '通知',
                    'message' => $notification->data['message'] ?? '',
                    'url' => is_string($url)
                        && str_starts_with($url, '/')
                        && ! str_starts_with($url, '//')
                            ? $url
                            : null,
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $data['unreadCount'],
            'tab' => $data['tab'],
            'pagination' => [
                'current_page' => $data['notifications']->currentPage(),
                'last_page' => $data['notifications']->lastPage(),
                'per_page' => $data['notifications']->perPage(),
                'total' => $data['notifications']->total(),
            ],
        ]);
    }

    /**
     * 指定された通知を既読にする。
     *
     * ログインユーザー本人の通知のみ操作可能。
     */
    public function markAsRead(
        Request $request,
        string $notification,
        MarkAsReadAction $action,
    ): JsonResponse {
        $notification = $action(
            user: $request->user(),
            notificationId: $notification,
        );

        return response()->json([
            'message' => '通知を既読にしました。',
            'notification' => [
                'id' => $notification->id,
                'read_at' => $notification->read_at?->toIso8601String(),
            ],
            'unread_count' => $request->user()
                ->unreadNotifications()
                ->count(),
        ]);
    }

    /**
     * ログインユーザーの未読通知をすべて既読にする。
     */
    public function markAllAsRead(
        Request $request,
        MarkAllAsReadAction $action,
    ): JsonResponse {
        $updatedCount = $action($request->user());

        return response()->json([
            'message' => 'すべての通知を既読にしました。',
            'updated_count' => $updatedCount,
            'unread_count' => 0,
        ]);
    }
}
