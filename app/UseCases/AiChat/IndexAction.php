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
     * @return Collection<int, AiChatConversation>
     */
    public function __invoke(User $user): Collection
    {
        return $user->aiChatConversations()
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->get();
    }
}
