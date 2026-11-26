<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GeminiChatService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Gemini APIとの通信およびレスポンス処理を検証するテスト。
 *
 * @group external-api
 * @group gemini
 */
class GeminiChatServiceTest extends TestCase
{
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test-model:generateContent';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai-chat.gemini.api_key' => 'test-api-key',
            'ai-chat.gemini.model' => 'gemini-test-model',
            'ai-chat.gemini.timeout' => 30,
        ]);

        Http::preventStrayRequests();
    }

    public function test_generates_response_from_successful_gemini_api_request(): void
    {
        // Arrange
        Http::fake([
            self::API_URL => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'テスト回答です。'],
                            ],
                        ],
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 15,
                    'candidatesTokenCount' => 20,
                ],
            ], 200),
        ]);

        // Act
        $result = app(GeminiChatService::class)->generate([
            [
                'role' => 'user',
                'content' => 'テスト質問です。',
            ],
        ]);

        // Assert
        $this->assertSame('テスト回答です。', $result['content']);
        $this->assertSame('gemini-test-model', $result['model']);
        $this->assertSame(15, $result['input_tokens']);
        $this->assertSame(20, $result['output_tokens']);
        $this->assertIsInt($result['response_time_ms']);
        $this->assertGreaterThanOrEqual(0, $result['response_time_ms']);

        Http::assertSentCount(1);
    }

    public function test_combines_multiple_text_parts_in_gemini_response(): void
    {
        // Arrange
        Http::fake([
            self::API_URL => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '回答の前半です。'],
                                ['text' => '回答の後半です。'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        // Act
        $result = app(GeminiChatService::class)->generate([
            [
                'role' => 'user',
                'content' => '詳しく教えてください。',
            ],
        ]);

        // Assert
        $this->assertSame(
            "回答の前半です。\n回答の後半です。",
            $result['content'],
        );

        Http::assertSentCount(1);
    }

    public function test_sends_expected_prompt_structure_and_message_order_to_gemini(): void
    {
        // Arrange
        Http::fake([
            self::API_URL => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '追加の回答です。'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $messages = [
            [
                'role' => 'user',
                'content' => '最初の質問です。',
            ],
            [
                'role' => 'assistant',
                'content' => '最初の回答です。',
            ],
            [
                'role' => 'user',
                'content' => '追加の質問です。',
            ],
        ];

        // Act
        app(GeminiChatService::class)->generate($messages);

        // Assert
        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::API_URL
                && $request->method() === 'POST'
                && $request->hasHeader('x-goog-api-key', 'test-api-key')
                && $request->hasHeader('Accept', 'application/json')
                && $request->data() === [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => '最初の質問です。'],
                            ],
                        ],
                        [
                            'role' => 'model',
                            'parts' => [
                                ['text' => '最初の回答です。'],
                            ],
                        ],
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => '追加の質問です。'],
                            ],
                        ],
                    ],
                ];
        });

        Http::assertSentCount(1);
    }

    public function test_returns_null_token_counts_when_usage_metadata_is_missing(): void
    {
        // Arrange
        Http::fake([
            self::API_URL => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'テスト回答です。'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        // Act
        $result = app(GeminiChatService::class)->generate([
            [
                'role' => 'user',
                'content' => 'テスト質問です。',
            ],
        ]);

        // Assert
        $this->assertSame('テスト回答です。', $result['content']);
        $this->assertNull($result['input_tokens']);
        $this->assertNull($result['output_tokens']);

        Http::assertSentCount(1);
    }

    public function test_throws_runtime_exception_when_gemini_connection_fails(): void
    {
        // Arrange
        $connectionException = new ConnectionException(
            'Simulated Gemini connection failure.',
        );

        $requestCount = 0;

        Http::fake([
            self::API_URL => function () use ($connectionException, &$requestCount) {
                $requestCount++;

                throw $connectionException;
            },
        ]);

        // Act
        try {
            app(GeminiChatService::class)->generate([
                [
                    'role' => 'user',
                    'content' => 'テスト質問です。',
                ],
            ]);

            $this->fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            // Assert
            $this->assertSame(
                'AIサービスとの通信に失敗しました。',
                $exception->getMessage(),
            );

            $this->assertSame(
                $connectionException,
                $exception->getPrevious(),
            );
        }

        $this->assertSame(1, $requestCount);
    }

    public function test_throws_runtime_exception_when_gemini_returns_http_error(): void
    {
        // Arrange
        Http::fake([
            self::API_URL => Http::response([
                'error' => [
                    'code' => 503,
                    'message' => 'Service unavailable.',
                ],
            ], 503),
        ]);

        // Act
        try {
            app(GeminiChatService::class)->generate([
                [
                    'role' => 'user',
                    'content' => 'テスト質問です。',
                ],
            ]);

            $this->fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            // Assert
            $this->assertSame(
                'AIサービスから正常な応答を取得できませんでした。',
                $exception->getMessage(),
            );
        }

        Http::assertSentCount(1);
    }

    public function test_throws_runtime_exception_when_gemini_response_format_is_invalid(): void
    {
        // Arrange
        Http::fake([
            self::API_URL => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => 'invalid-response-format',
                        ],
                    ],
                ],
            ], 200),
        ]);

        // Act
        try {
            app(GeminiChatService::class)->generate([
                [
                    'role' => 'user',
                    'content' => 'テスト質問です。',
                ],
            ]);

            $this->fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            // Assert
            $this->assertSame(
                'AIサービスの応答形式が不正です。',
                $exception->getMessage(),
            );
        }

        Http::assertSentCount(1);
    }

    public function test_throws_runtime_exception_when_gemini_returns_empty_answer(): void
    {
        // Arrange
        Http::fake([
            self::API_URL => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => ''],
                                ['text' => " \n "],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        // Act
        try {
            app(GeminiChatService::class)->generate([
                [
                    'role' => 'user',
                    'content' => 'テスト質問です。',
                ],
            ]);

            $this->fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            // Assert
            $this->assertSame(
                'AIサービスから回答を取得できませんでした。',
                $exception->getMessage(),
            );
        }

        Http::assertSentCount(1);
    }

    public function test_throws_runtime_exception_without_api_request_when_api_key_is_missing(): void
    {
        // Arrange
        config([
            'ai-chat.gemini.api_key' => '',
        ]);

        Http::fake([
            self::API_URL => Http::response([], 200),
        ]);

        // Act
        try {
            app(GeminiChatService::class)->generate([
                [
                    'role' => 'user',
                    'content' => 'テスト質問です。',
                ],
            ]);

            $this->fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            // Assert
            $this->assertSame(
                'Gemini APIの設定が完了していません。',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
    }

    public function test_throws_runtime_exception_without_api_request_when_messages_are_empty(): void
    {
        // Arrange
        Http::fake([
            self::API_URL => Http::response([], 200),
        ]);

        // Act
        try {
            app(GeminiChatService::class)->generate([]);

            $this->fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            // Assert
            $this->assertSame(
                'Geminiに送信するメッセージがありません。',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
    }
}
