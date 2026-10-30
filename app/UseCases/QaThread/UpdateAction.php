<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\QaThread;

/**
 * 質問投稿の更新ユースケース。
 *
 * - certification_id は不可変
 * - title / body のみ更新する
 */
final class UpdateAction
{
    /**
     * @param array{title: string, body: string} $validated
     */
    public function __invoke(QaThread $thread, array $validated): QaThread
    {
        $thread->update([
            'title' => $validated['title'],
            'body' => $validated['body'],
        ]);

        return $thread->fresh();
    }
}
