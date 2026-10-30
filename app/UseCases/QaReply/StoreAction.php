<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Support\Facades\DB;

/**
 * 質問投稿への返信を作成するユースケース。
 */
final class StoreAction
{
    /**
     * @param array{body: string} $validated
     */
    public function __invoke(
        QaThread $thread,
        User $user,
        array $validated,
    ): QaReply {
        $reply = $thread->replies()->create([
            'user_id' => $user->id,
            'body' => $validated['body'],
        ]);

        // 自分自身の質問への返信では通知しない。
        if ($thread->user_id !== $user->id) {
            DB::afterCommit(function () use ($thread, $reply): void {
                $thread->user?->notify(
                    new QaReplyReceivedNotification($thread, $reply)
                );
            });
        }

        return $reply;
    }
}
