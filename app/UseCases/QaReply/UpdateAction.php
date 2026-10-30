<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;

/**
 * 質問投稿への返信を更新するユースケース。
 */
final class UpdateAction
{
    /**
     * @param array{body: string} $validated
     */
    public function __invoke(QaReply $reply, array $validated): QaReply
    {
        $reply->update([
            'body' => $validated['body'],
        ]);

        return $reply->fresh();
    }
}
