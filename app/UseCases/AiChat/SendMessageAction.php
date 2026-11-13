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
        $usage = ($this->consumeDailyQuota)($user);

        $assistantMessage = null;
        $geminiSucceeded = false;

        try {
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
            $geminiSucceeded = true;

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
            Log::warning('AI chat message processing failed', [
                'conversation_id' => $conversation->id,
                'exception_type' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
            ]);

            // Gemini APIから回答を取得できなかった場合、
            // または質問の保存処理が失敗した場合は質問回数を返却する。
            if (! $geminiSucceeded) {
                try {
                    $this->consumeDailyQuota->refund($usage);
                } catch (Throwable $refundException) {
                    Log::error('AI chat daily quota refund failed', [
                        'conversation_id' => $conversation->id,
                        'exception_type' => get_class($refundException),
                        'exception_message' => $refundException->getMessage(),
                    ]);
                }
            }

            // AI回答用レコードが作成されている場合のみ、
            // エラー状態への更新を試みる。
            if ($assistantMessage !== null) {
                try {
                    $assistantMessage->update([
                        'status' => AiChatMessageStatus::Error,
                        'content' => null,
                        'error_detail' => 'AIサービスから回答を取得できませんでした。',
                    ]);
                } catch (Throwable $updateException) {
                    Log::error('AI chat error status update failed', [
                        'conversation_id' => $conversation->id,
                        'exception_type' => get_class($updateException),
                        'exception_message' => $updateException->getMessage(),
                    ]);
                }
            }

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
