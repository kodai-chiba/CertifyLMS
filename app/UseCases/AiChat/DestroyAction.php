<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;

class DestroyAction
{
    /**
     * AIチャットの会話を削除する。
     *
     * 呼び出し元でPolicyによる削除認可を実施する。
     * 関連メッセージはDBの外部キー制約で連鎖削除される。
     * 日次質問回数は会話とは独立して保持する。
     */
    public function __invoke(AiChatConversation $conversation): void
    {
        $conversation->delete();
    }
}
