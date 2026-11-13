<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;
use App\Models\User;

class StoreAction
{
    /**
     * 受講生本人の新しいAIチャット会話を作成する。
     *
     * enrollment_id と section_id は、呼び出し元で
     * 本人の閲覧権限を検証済みの値のみを受け取る。
     *
     * @param array{enrollment_id?: string|null, section_id?: string|null} $data
     */
    public function __invoke(User $user, array $data = []): AiChatConversation
    {
        return $user->aiChatConversations()->create([
            'enrollment_id' => $data['enrollment_id'] ?? null,
            'section_id' => $data['section_id'] ?? null,
            'title' => '新しい会話',
        ]);
    }
}
