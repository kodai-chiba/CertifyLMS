<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\UseCases\Notification\IndexAction;
use App\UseCases\Notification\MarkAllAsReadAction;
use App\UseCases\Notification\MarkAsReadAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    /**
     * ログインユーザーの通知一覧を表示する。
     */
    public function index(Request $request, IndexAction $action): View
    {
        $data = $action(
            user: $request->user(),
            tab: $request->query('tab', 'all'),
        );

        return view('notifications.index', $data);
    }

    /**
     * 通知ポップオーバー用の通知データを取得する。
     */
    public function feed(Request $request, IndexAction $action): JsonResponse
    {
        $data = $action(
            user: $request->user(),
            tab: $request->query('tab', 'all'),
        );

        $notifications = $data['notifications']->getCollection()
            ->map(function ($notification): array {
                return [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? '通知',
                    'message' => $notification->data['message'] ?? '',
                    'url' => route('notifications.markAsRead', $notification->id),
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $data['unreadCount'],
            'tab' => $data['tab'],
        ]);
    }

    /**
     * 指定された通知を既読にする。
     */
    public function markAsRead(
        Request $request,
        string $notification,
        MarkAsReadAction $action,
    ): RedirectResponse {
        $notification = $action(
            user: $request->user(),
            notificationId: $notification,
        );

        $url = $notification->data['url'] ?? null;

        if (is_string($url) && str_starts_with($url, '/')
            && ! str_starts_with($url, '//')) {
            return redirect()->to($url);
        }

        return redirect()
            ->route('notifications.index')
            ->with('success', '通知を既読にしました。');
    }

    /**
     * ログインユーザーの未読通知をすべて既読にする。
     */
    public function markAllAsRead(
        Request $request,
        MarkAllAsReadAction $action,
    ): RedirectResponse {
        $action($request->user());

        return redirect()
            ->route('notifications.index')
            ->with('success', 'すべての通知を既読にしました。');
    }
}
