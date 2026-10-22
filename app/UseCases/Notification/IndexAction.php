<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class IndexAction
{
    /**
     * @return array{
     *     notifications: LengthAwarePaginator,
     *     unreadCount: int,
     *     tab: string
     * }
     */
    public function __invoke(User $user, string $tab = 'all'): array
    {
        $tab = $tab === 'unread' ? 'unread' : 'all';

        $notifications = $tab === 'unread'
            ? $user->unreadNotifications()
            : $user->notifications();

        return [
            'notifications' => $notifications
                ->orderByDesc('created_at')
                ->paginate(15)
                ->withQueryString(),
            'unreadCount' => $user->unreadNotifications()->count(),
            'tab' => $tab,
        ];
    }
}
