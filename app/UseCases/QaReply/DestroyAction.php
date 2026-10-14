<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;
use Illuminate\Support\Facades\DB;

/**
 * 質問投稿への返信を削除するユースケース。
 */
final class DestroyAction
{
    public function __invoke(QaReply $reply): void
    {
        DB::transaction(fn () => $reply->delete());
    }
}
