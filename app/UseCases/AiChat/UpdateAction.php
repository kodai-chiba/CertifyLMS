<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;

class UpdateAction
{
    /**
     * AIチャットの会話タイトルを更新する。
     *
     * 呼び出し元でPolicyによる更新認可と
     * FormRequestによる入力値の検証を実施する。
     */
    public function __invoke(
        AiChatConversation $conversation,
        string $title
    ): AiChatConversation {
        $conversation->update([
            'title' => $title,
        ]);

        return $conversation->refresh();
    }
}
