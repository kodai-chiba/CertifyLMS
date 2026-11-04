<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiChatService
{
    /**
     * Gemini APIに質問を送信する。
     *
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @return array{
     *     content: string,
     *     model: string,
     *     input_tokens: int|null,
     *     output_tokens: int|null,
     *     response_time_ms: int
     * }
     */
    public function generate(array $messages): array
    {
        $apiKey = (string) config('ai-chat.gemini.api_key', '');
        $model = (string) config('ai-chat.gemini.model', 'gemini-3.5-flash-lite');
        $timeout = max(1, (int) config('ai-chat.gemini.timeout', 30));

        if ($apiKey === '') {
            throw new RuntimeException('Gemini APIの設定が完了していません。');
        }

        $contents = [];

        foreach ($messages as $message) {
            $contents[] = [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [
                    [
                        'text' => $message['content'],
                    ],
                ],
            ];
        }

        if ($contents === []) {
            throw new RuntimeException('Geminiに送信するメッセージがありません。');
        }

        $startedAt = microtime(true);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                ])
                ->post(
                    'https://generativelanguage.googleapis.com/v1beta/models/'
                    .rawurlencode($model)
                    .':generateContent',
                    [
                        'contents' => $contents,
                    ]
                );
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'AIサービスとの通信に失敗しました。',
                0,
                $exception
            );
        }

        $responseTimeMs = (int) round(
            (microtime(true) - $startedAt) * 1000
        );

        if (! $response->successful()) {
            throw new RuntimeException('AIサービスから正常な応答を取得できませんでした。');
        }

        $parts = $response->json('candidates.0.content.parts');

        if (! is_array($parts)) {
            throw new RuntimeException('AIサービスの応答形式が不正です。');
        }

        $texts = [];

        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $texts[] = $part['text'];
            }
        }

        $content = trim(implode("\n", $texts));

        if ($content === '') {
            throw new RuntimeException('AIサービスから回答を取得できませんでした。');
        }

        $inputTokens = $response->json('usageMetadata.promptTokenCount');
        $outputTokens = $response->json('usageMetadata.candidatesTokenCount');

        return [
            'content' => $content,
            'model' => $model,
            'input_tokens' => is_numeric($inputTokens) ? (int) $inputTokens : null,
            'output_tokens' => is_numeric($outputTokens) ? (int) $outputTokens : null,
            'response_time_ms' => $responseTimeMs,
        ];
    }
}
