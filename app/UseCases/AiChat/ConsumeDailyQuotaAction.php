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
        $limit = max(0, (int) config('ai-chat.daily_limit', 50));

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

    /**
     * Gemini APIエラー時に消費した質問回数を返却する。
     *
     * 質問時に取得した利用履歴を指定することで、
     * 日付をまたいだ場合も正しい日の利用回数を減算する。
     */
    public function refund(AiChatDailyUsage $usage): void
    {
        DB::transaction(function () use ($usage): void {
            $lockedUsage = AiChatDailyUsage::query()
                ->whereKey($usage->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedUsage->question_count > 0) {
                $lockedUsage->decrement('question_count');
            }
        }, 3);
    }
}
