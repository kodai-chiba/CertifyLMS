<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatDailyUsage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class ConsumeDailyQuotaAction
{
    /**
     * 当日の質問回数を1回消費する。
     *
     * @throws TooManyRequestsHttpException
     */
    public function __invoke(User $user): AiChatDailyUsage
    {
        $today = now('Asia/Tokyo')->toDateString();
        $limit = max(0, (int) config('ai-chat.daily_limit', 10));

        return DB::transaction(function () use ($user, $today, $limit): AiChatDailyUsage {
            // 初回利用時の同時INSERTによる重複を防ぐ。
            DB::table('ai_chat_daily_usages')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'usage_date' => $today,
                'question_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 同一ユーザー・同一日付の更新を直列化する。
            $usage = AiChatDailyUsage::query()
                ->where('user_id', $user->id)
                ->whereDate('usage_date', $today)
                ->lockForUpdate()
                ->firstOrFail();

            if ($usage->question_count >= $limit) {
                throw new TooManyRequestsHttpException(
                    60,
                    '本日のAIチャットの質問回数が上限に達しました。明日以降に再度お試しください。'
                );
            }

            $usage->increment('question_count');

            return $usage->refresh();
        }, 3);
    }
}
