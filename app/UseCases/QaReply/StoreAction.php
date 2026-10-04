<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;

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
        return $thread->replies()->create([
            'user_id' => $user->id,
            'body' => $validated['body'],
        ]);
    }
}
