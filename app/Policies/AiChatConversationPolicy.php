<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AiChatConversation;
use App\Models\User;

/**
 * Gemini AIチャットの会話に対するアクセス権限を管理するPolicy。
 *
 * AIチャット機能が有効な場合に限り、
 * 受講中の受講生が自身の会話を操作できる。
 */
class AiChatConversationPolicy
{
    /**
     * 会話一覧を閲覧できるか判定する。
     */
    public function viewAny(User $user): bool
    {
        return $this->canUseAiChat($user);
    }

    /**
     * 指定した会話を閲覧できるか判定する。
     */
    public function view(User $user, AiChatConversation $aiChatConversation): bool
    {
        return $this->canAccessConversation($user, $aiChatConversation);
    }

    /**
     * 新しい会話を作成できるか判定する。
     */
    public function create(User $user): bool
    {
        return $this->canUseAiChat($user);
    }

    /**
     * 会話のタイトルを変更できるか判定する。
     */
    public function update(User $user, AiChatConversation $aiChatConversation): bool
    {
        return $this->canAccessConversation($user, $aiChatConversation);
    }

    /**
     * 会話を削除できるか判定する。
     */
    public function delete(User $user, AiChatConversation $aiChatConversation): bool
    {
        return $this->canAccessConversation($user, $aiChatConversation);
    }

    /**
     * 会話の復元は許可しない。
     */
    public function restore(User $user, AiChatConversation $aiChatConversation): bool
    {
        return false;
    }

    /**
     * 会話の完全削除は許可しない。
     */
    public function forceDelete(User $user, AiChatConversation $aiChatConversation): bool
    {
        return false;
    }

    /**
     * 指定した会話に質問を送信できるか判定する。
     */
    public function sendMessage(User $user, AiChatConversation $aiChatConversation): bool
    {
        return $this->canAccessConversation($user, $aiChatConversation);
    }

    /**
     * AIチャット機能を利用できるユーザーか判定する。
     */
    private function canUseAiChat(User $user): bool
    {
        return (bool) config('ai-chat.enabled', false)
            && $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress;
    }

    /**
     * 指定した会話の操作権限があるか判定する。
     */
    private function canAccessConversation(
        User $user,
        AiChatConversation $aiChatConversation
    ): bool {
        return $this->canUseAiChat($user)
            && $aiChatConversation->user_id === $user->id;
    }
}
