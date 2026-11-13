<?php

declare(strict_types=1);

namespace Tests\Feature\AiChat;

use App\Models\AiChatConversation;
use App\Models\AiChatDailyUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiChatDailyQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai-chat.enabled', true);
        config()->set('ai-chat.daily_limit', 50);
        config()->set('ai-chat.gemini.api_key', 'test-api-key');
        config()->set('ai-chat.gemini.model', 'gemini-test-model');

        Http::preventStrayRequests();
    }

    private function fakeSuccessfulGeminiResponse(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'テスト用のAI回答です。'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_student_cannot_send_message_after_reaching_daily_limit(): void
    {
        $this->fakeSuccessfulGeminiResponse();

        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::query()->create([
            'user_id' => $student->id,
            'title' => '質問回数制限のテスト',
        ]);

        AiChatDailyUsage::query()->create([
            'user_id' => $student->id,
            'usage_date' => now('Asia/Tokyo')->toDateString(),
            'question_count' => 50,
        ]);

        $response = $this->actingAs($student)
            ->postJson(
                route('ai-chat.conversations.messages.store', $conversation),
                [
                    'content' => '51通目の質問です。',
                ]
            );

        $response->assertStatus(429);

        $this->assertDatabaseHas('ai_chat_daily_usages', [
            'user_id' => $student->id,
            'usage_date' => now('Asia/Tokyo')->toDateString(),
            'question_count' => 50,
        ]);

        $this->assertDatabaseCount('ai_chat_messages', 0);

        Http::assertNothingSent();
    }

    public function test_student_can_send_message_on_next_day(): void
    {
        $this->fakeSuccessfulGeminiResponse();

        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::query()->create([
            'user_id' => $student->id,
            'title' => '日付変更のテスト',
        ]);

        AiChatDailyUsage::query()->create([
            'user_id' => $student->id,
            'usage_date' => now('Asia/Tokyo')
                ->subDay()
                ->toDateString(),
            'question_count' => 50,
        ]);

        $response = $this->actingAs($student)
            ->postJson(
                route('ai-chat.conversations.messages.store', $conversation),
                [
                    'content' => '翌日の質問です。',
                ]
            );

        $response->assertOk();

        $this->assertDatabaseHas('ai_chat_daily_usages', [
            'user_id' => $student->id,
            'usage_date' => now('Asia/Tokyo')->toDateString(),
            'question_count' => 1,
        ]);

        $this->assertDatabaseHas('ai_chat_daily_usages', [
            'user_id' => $student->id,
            'usage_date' => now('Asia/Tokyo')
                ->subDay()
                ->toDateString(),
            'question_count' => 50,
        ]);

        Http::assertSentCount(1);
    }

    public function test_daily_limit_can_be_changed_by_configuration(): void
    {
        $this->fakeSuccessfulGeminiResponse();

        config()->set('ai-chat.daily_limit', 2);

        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::query()->create([
            'user_id' => $student->id,
            'title' => '上限変更のテスト',
        ]);

        AiChatDailyUsage::query()->create([
            'user_id' => $student->id,
            'usage_date' => now('Asia/Tokyo')->toDateString(),
            'question_count' => 2,
        ]);

        $this->actingAs($student)
            ->postJson(
                route('ai-chat.conversations.messages.store', $conversation),
                [
                    'content' => '上限超過の質問です。',
                ]
            )
            ->assertStatus(429);

        $this->assertDatabaseCount('ai_chat_messages', 0);

        Http::assertNothingSent();
    }

    public function test_gemini_api_error_does_not_consume_daily_quota(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'message' => 'Gemini API error',
                ],
            ], 500),
        ]);

        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::query()->create([
            'user_id' => $student->id,
            'title' => 'APIエラー時の質問回数テスト',
        ]);

        $response = $this->actingAs($student)
            ->postJson(
                route('ai-chat.conversations.messages.store', $conversation),
                [
                    'content' => 'APIエラーになる質問です。',
                ]
            );

        $response->assertStatus(502);

        $this->assertDatabaseHas('ai_chat_daily_usages', [
            'user_id' => $student->id,
            'usage_date' => now('Asia/Tokyo')->toDateString(),
            'question_count' => 0,
        ]);

        Http::assertSentCount(1);
    }

    public function test_student_can_retry_after_gemini_api_error(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'error' => [
                        'message' => 'Gemini API error',
                    ],
                ], 500)
                ->push([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    ['text' => '再質問への回答です。'],
                                ],
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::query()->create([
            'user_id' => $student->id,
            'title' => 'APIエラー後の再質問テスト',
        ]);

        $this->actingAs($student)
            ->postJson(
                route('ai-chat.conversations.messages.store', $conversation),
                [
                    'content' => '最初の質問です。',
                ]
            )
            ->assertStatus(502);

        $this->assertDatabaseHas('ai_chat_daily_usages', [
            'user_id' => $student->id,
            'usage_date' => now('Asia/Tokyo')->toDateString(),
            'question_count' => 0,
        ]);

        $this->actingAs($student)
            ->postJson(
                route('ai-chat.conversations.messages.store', $conversation),
                [
                    'content' => '再質問です。',
                ]
            )
            ->assertOk();

        $this->assertDatabaseHas('ai_chat_daily_usages', [
            'user_id' => $student->id,
            'usage_date' => now('Asia/Tokyo')->toDateString(),
            'question_count' => 1,
        ]);

        Http::assertSentCount(2);
    }
}
