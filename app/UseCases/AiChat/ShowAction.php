<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;

class ShowAction
{
    /**
     * AIチャットの会話詳細と関連情報を取得する。
     *
     * 呼び出し元でPolicyによる閲覧認可を実施する。
     */
    public function __invoke(AiChatConversation $conversation): AiChatConversation
    {
        return $conversation->load([
            'messages',
            'enrollment.certification',
            'section',
        ]);
    }
}
