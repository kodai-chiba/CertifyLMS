<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;

final class MarkAllAsReadAction
{
    /**
     * ログインユーザーの未読通知をすべて既読にする。
     *
     * 他のユーザー宛ての通知には影響しない。
     */
    public function __invoke(User $user): int
    {
        return $user->unreadNotifications()
            ->update(['read_at' => now()]);
    }
}
