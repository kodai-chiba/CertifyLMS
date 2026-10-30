<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

final class MarkAsReadAction
{
    /**
     * 指定された通知を既読にする。
     *
     * 自分宛ての通知のみ操作を許可する。
     */
    public function __invoke(User $user, string $notificationId): DatabaseNotification
    {
        $notification = $user->notifications()
            ->whereKey($notificationId)
            ->firstOrFail();

        if ($notification->unread()) {
            $notification->markAsRead();
        }

        return $notification;
    }
}
