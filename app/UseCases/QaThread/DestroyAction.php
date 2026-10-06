<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\UserRole;
use App\Exceptions\QaThread\QaThreadHasRepliesException;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 質問投稿の削除ユースケース。
 *
 * 受講生は回答が1件でも存在する質問を削除不可。
 * 管理者は回答の有無にかかわらず削除可能で、紐づく回答も同時に削除される。
 * 違反時は `QaThreadHasRepliesException`(409)。
 */
final class DestroyAction
{
    /**
     * @throws QaThreadHasRepliesException
     */
    public function __invoke(QaThread $thread, User $actor): void
    {
        if (
            $actor->role !== UserRole::Admin
            && $thread->replies()->exists()
        ) {
            throw new QaThreadHasRepliesException;
        }

        DB::transaction(fn () => $thread->delete());
    }
}
