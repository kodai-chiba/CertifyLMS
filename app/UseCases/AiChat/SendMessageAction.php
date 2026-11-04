<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\GeminiChatService;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendMessageAction
{
    public function __construct(
        private readonly ConsumeDailyQuotaAction $consumeDailyQuota,
        private readonly GeminiChatService $geminiChatService,
    ) {}

    /**
     * 質問を保存し、Geminiから回答を取得する。
     *
     * @return array{
     *     user_message: AiChatMessage,
     *     assistant_message: AiChatMessage,
     *     conversation: AiChatConversation
     * }
     */
    public function __invoke(
        User $user,
        AiChatConversation $conversation,
        string $content
    ): array {
        // 質問回数を消費する。
        ($this->consumeDailyQuota)($user);

        // ユーザーの質問を保存する。
        $userMessage = $conversation->messages()->create([
            'role' => AiChatMessageRole::User,
            'content' => $content,
            'status' => AiChatMessageStatus::Completed,
        ]);

        // AI回答用のレコードを作成する。
        $assistantMessage = $conversation->messages()->create([
            'role' => AiChatMessageRole::Assistant,
            'content' => null,
            'status' => AiChatMessageStatus::Pending,
        ]);

        try {
            // 完了済みメッセージだけを会話履歴として取得する。
            $messages = $conversation->messages()
                ->where('status', AiChatMessageStatus::Completed->value)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn (AiChatMessage $message): array => [
                    'role' => $message->role->value,
                    'content' => (string) $message->content,
                ])
                ->all();

            // Gemini APIへの通信中はDBトランザクションを保持しない。
            $response = $this->geminiChatService->generate($messages);

            // AI回答を保存する。
            $assistantMessage->update([
                'content' => $response['content'],
                'status' => AiChatMessageStatus::Completed,
                'model' => $response['model'],
                'input_tokens' => $response['input_tokens'],
                'output_tokens' => $response['output_tokens'],
                'response_time_ms' => $response['response_time_ms'],
                'error_detail' => null,
            ]);
        } catch (Throwable $exception) {
            // 利用者には詳細を公開せず、調査に必要な情報だけをログへ記録する。
            Log::warning('AI chat generation failed', [
                'conversation_id' => $conversation->id,
                'exception_type' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
            ]);

            $assistantMessage->update([
                'status' => AiChatMessageStatus::Error,
                'content' => null,
                'error_detail' => 'AIサービスから回答を取得できませんでした。',
            ]);

            throw new RuntimeException(
                'AIサービスから回答を取得できませんでした。時間をおいて再度お試しください。',
                0,
                $exception
            );
        }

        return [
            'user_message' => $userMessage->refresh(),
            'assistant_message' => $assistantMessage->refresh(),
            'conversation' => $conversation->refresh(),
        ];
    }
}
