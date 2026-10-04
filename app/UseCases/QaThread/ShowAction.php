<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\QaThread;

/**
 * 質問掲示板詳細を関連データとともに取得するユースケース。
 * 質問の資格 / 投稿者 / 返信投稿者を Eager Loading し、
 * 返信は投稿日時の昇順で取得する。
 */
final class ShowAction
{
    public function __invoke(QaThread $qaThread): QaThread
    {
        return $qaThread
            ->load([
                'certification',
                'user',
                'replies' => fn ($query) => $query->orderBy('created_at'),
                'replies.user',
            ])
            ->loadCount('replies');
    }
}
