<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;
use Illuminate\Support\Facades\DB;

/**
 * 質問投稿を解決済みから未解決に戻すユースケース。
 */
final class UnresolveAction
{
    public function __invoke(QaThread $thread): QaThread
    {
        return DB::transaction(function () use ($thread) {
            $thread->update([
                'status' => QaThreadStatus::Unresolved->value,
                'resolved_at' => null,
            ]);

            return $thread->fresh();
        });
    }
}
