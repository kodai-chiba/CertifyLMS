<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class IndexAction
{
    /**
     * 受講生本人のAIチャット会話一覧を取得する。
     *
     * 最新30件を初期値とし、環境変数から取得件数を変更できる。
     * 取得対象外の会話は削除せず、DBに保持する。
     *
     * @return Collection<int, AiChatConversation>
     */
    public function __invoke(User $user): Collection
    {
        $limit = max(1, (int) config('ai-chat.conversation_limit', 30));

        return $user->aiChatConversations()
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
