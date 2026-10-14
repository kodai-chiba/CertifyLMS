<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 質問掲示板一覧を操作者のロールと検索条件に応じて取得するユースケース。
 *
 * student は公開資格の全質問、coach は公開中の担当資格のみ、
 * admin は公開状態を問わず全質問を取得する。
 */
final class IndexAction
{
    public function __invoke(
        User $viewer,
        ?string $certificationId,
        ?QaThreadStatus $status,
        ?string $keyword,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = QaThread::query()
            ->forUser($viewer)
            ->with(['certification', 'user'])
            ->withCount('replies');

        $query
            ->certification($certificationId)
            ->status($status)
            ->keyword($keyword);

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }
}
