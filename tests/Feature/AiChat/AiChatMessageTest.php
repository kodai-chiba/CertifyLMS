<?php

declare(strict_types=1);

namespace Tests\Feature\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatDailyUsage;
use App\Models\AiChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * @group external-api
 * @group gemini
 */
class AiChatMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai-chat.enabled', true);
        config()->set('ai-chat.gemini.api_key', 'test-api-key');
        config()->set('ai-chat.gemini.model', 'gemini-test-model');

        Http::preventStrayRequests();
    }

    public function test_student_can_send_message_and_receive_ai_response(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'MVCはModel、View、Controllerの略です。'],
                            ],
                        ],
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 15,
                    'candidatesTokenCount' => 25,
                ],
            ], 200),
        ]);

        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::query()->create([
            'user_id' => $student->id,
            'title' => 'Laravelの質問',
        ]);

        $response = $this->actingAs($student)
            ->postJson(
                route('ai-chat.conversations.messages.store', $conversation),
                [
                    'content' => 'LaravelのMVCについて教えてください。',
                ]
            );

        $response->assertOk();

        $this->assertDatabaseHas('ai_chat_messages', [
            'conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::User->value,
            'content' => 'LaravelのMVCについて教えてください。',
            'status' => AiChatMessageStatus::Completed->value,
        ]);

        $this->assertDatabaseHas('ai_chat_messages', [
            'conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::Assistant->value,
            'content' => 'MVCはModel、View、Controllerの略です。',
            'status' => AiChatMessageStatus::Completed->value,
            'model' => 'gemini-test-model',
            'input_tokens' => 15,
            'output_tokens' => 25,
        ]);

        $this->assertSame(
            2,
            AiChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->count()
        );

        $usage = AiChatDailyUsage::query()
            ->where('user_id', $student->id)
            ->firstOrFail();

        $this->assertSame(1, $usage->question_count);

        $this->assertSame(
            now('Asia/Tokyo')->toDateString(),
            $usage->usage_date->toDateString()
        );

        $this->assertNotNull(
            $conversation->refresh()->last_message_at
        );

        Http::assertSentCount(1);
    }

    public function test_ai_api_error_returns_502_and_saves_error_message(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 503,
                    'message' => 'Service unavailable',
                    'status' => 'UNAVAILABLE',
                ],
            ], 503),
        ]);

        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::query()->create([
            'user_id' => $student->id,
            'title' => 'APIエラー確認用の会話',
        ]);

        $response = $this->actingAs($student)
            ->postJson(
                route('ai-chat.conversations.messages.store', $conversation),
                [
                    'content' => 'Laravelについて教えてください。',
                ]
            );

        $response
            ->assertStatus(502)
            ->assertJson([
                'message' => 'AIサービスから回答を取得できませんでした。',
            ]);

        $this->assertDatabaseHas('ai_chat_messages', [
            'conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::User->value,
            'content' => 'Laravelについて教えてください。',
            'status' => AiChatMessageStatus::Completed->value,
        ]);

        $this->assertDatabaseHas('ai_chat_messages', [
            'conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::Assistant->value,
            'content' => null,
            'status' => AiChatMessageStatus::Error->value,
            'error_detail' => 'AIサービスから回答を取得できませんでした。',
        ]);

        $this->assertSame(
            2,
            AiChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->count()
        );

        $usage = AiChatDailyUsage::query()
            ->where('user_id', $student->id)
            ->firstOrFail();

        // Gemini APIエラー時は質問回数に含めない。
        $this->assertSame(0, $usage->question_count);

        Http::assertSentCount(1);
    }

    public function test_sends_only_completed_messages_in_chronological_order_to_gemini(): void
    {
        // Arrange
        $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test-model:generateContent';

        Http::fake([
            $apiUrl => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Controllerはリクエストを処理します。'],
                            ],
                        ],
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 30,
                    'candidatesTokenCount' => 20,
                ],
            ], 200),
        ]);

        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::query()->create([
            'user_id' => $student->id,
            'title' => 'Laravelの質問',
        ]);

        $otherConversation = AiChatConversation::query()->create([
            'user_id' => $student->id,
            'title' => '別の会話',
        ]);

        $baseTime = now();

        // 作成順ではなく、メッセージの日時順に送信されることを検証する。
        // テストデータの日時を明示的に保存するため、forceCreateを使用する。
        AiChatMessage::query()->forceCreate([
            'conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::Assistant->value,
            'content' => 'MVCはModel、View、Controllerの略です。',
            'status' => AiChatMessageStatus::Completed->value,
            'created_at' => $baseTime->copy()->subMinutes(4),
            'updated_at' => $baseTime->copy()->subMinutes(4),
        ]);

        AiChatMessage::query()->forceCreate([
            'conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::User->value,
            'content' => 'LaravelのMVCについて教えてください。',
            'status' => AiChatMessageStatus::Completed->value,
            'created_at' => $baseTime->copy()->subMinutes(5),
            'updated_at' => $baseTime->copy()->subMinutes(5),
        ]);

        AiChatMessage::query()->forceCreate([
            'conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::Assistant->value,
            'content' => null,
            'status' => AiChatMessageStatus::Pending->value,
            'created_at' => $baseTime->copy()->subMinutes(3),
            'updated_at' => $baseTime->copy()->subMinutes(3),
        ]);

        AiChatMessage::query()->forceCreate([
            'conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::Assistant->value,
            'content' => null,
            'status' => AiChatMessageStatus::Error->value,
            'error_detail' => 'AIサービスから回答を取得できませんでした。',
            'created_at' => $baseTime->copy()->subMinutes(2),
            'updated_at' => $baseTime->copy()->subMinutes(2),
        ]);

        AiChatMessage::query()->forceCreate([
            'conversation_id' => $otherConversation->id,
            'role' => AiChatMessageRole::User->value,
            'content' => '別の会話の質問です。',
            'status' => AiChatMessageStatus::Completed->value,
            'created_at' => $baseTime->copy()->subMinute(),
            'updated_at' => $baseTime->copy()->subMinute(),
        ]);

        // Act
        $response = $this->actingAs($student)
            ->postJson(
                route('ai-chat.conversations.messages.store', $conversation),
                [
                    'content' => 'Controllerの役割を教えてください。',
                ]
            );

        // Assert
        $response->assertOk();

        Http::assertSent(function (Request $request) use ($apiUrl): bool {
            return $request->url() === $apiUrl
                && $request->method() === 'POST'
                && $request->hasHeader('x-goog-api-key', 'test-api-key')
                && $request->data() === [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => 'LaravelのMVCについて教えてください。'],
                            ],
                        ],
                        [
                            'role' => 'model',
                            'parts' => [
                                ['text' => 'MVCはModel、View、Controllerの略です。'],
                            ],
                        ],
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => 'Controllerの役割を教えてください。'],
                            ],
                        ],
                    ],
                ];
        });

        Http::assertSentCount(1);

        $this->assertDatabaseHas('ai_chat_messages', [
            'conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::User->value,
            'content' => 'Controllerの役割を教えてください。',
            'status' => AiChatMessageStatus::Completed->value,
        ]);

        $this->assertDatabaseHas('ai_chat_messages', [
            'conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::Assistant->value,
            'content' => 'Controllerはリクエストを処理します。',
            'status' => AiChatMessageStatus::Completed->value,
            'model' => 'gemini-test-model',
            'input_tokens' => 30,
            'output_tokens' => 20,
        ]);

        $this->assertSame(
            6,
            AiChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->count()
        );

        $this->assertSame(
            1,
            AiChatMessage::query()
                ->where('conversation_id', $otherConversation->id)
                ->count()
        );

        $usage = AiChatDailyUsage::query()
            ->where('user_id', $student->id)
            ->firstOrFail();

        $this->assertSame(1, $usage->question_count);
    }
}
