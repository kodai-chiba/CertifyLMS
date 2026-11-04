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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

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

        $this->assertSame(1, $usage->question_count);

        Http::assertSentCount(1);
    }
}
