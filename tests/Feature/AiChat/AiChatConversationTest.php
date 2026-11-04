<?php

declare(strict_types=1);

namespace Tests\Feature\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiChatConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai-chat.enabled', true);
    }

    public function test_student_can_create_conversation(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($student)
            ->post(route('ai-chat.conversations.store'), []);

        $conversation = AiChatConversation::query()
            ->where('user_id', $student->id)
            ->firstOrFail();

        $response->assertRedirect(
            route('ai-chat.conversations.show', $conversation)
        );

        $this->assertSame('新しい会話', $conversation->title);
        $this->assertNull($conversation->enrollment_id);
        $this->assertNull($conversation->section_id);
    }

    public function test_student_can_view_own_conversation(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::query()->create([
            'user_id' => $student->id,
            'title' => '表示確認用の会話',
        ]);

        $this->actingAs($student)
            ->get(route('ai-chat.conversations.show', $conversation))
            ->assertOk()
            ->assertSee('表示確認用の会話');
    }

    public function test_student_can_update_own_conversation(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::query()->create([
            'user_id' => $student->id,
            'title' => '変更前のタイトル',
        ]);

        $this->actingAs($student)
            ->patch(route('ai-chat.conversations.update', $conversation), [
                'title' => '変更後のタイトル',
            ])
            ->assertRedirect(
                route('ai-chat.conversations.show', $conversation)
            );

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
            'title' => '変更後のタイトル',
        ]);
    }

    public function test_student_can_delete_own_conversation(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::query()->create([
            'user_id' => $student->id,
            'title' => '削除対象の会話',
        ]);

        $this->actingAs($student)
            ->delete(route('ai-chat.conversations.destroy', $conversation))
            ->assertRedirect(route('ai-chat.index'));

        $this->assertDatabaseMissing('ai_chat_conversations', [
            'id' => $conversation->id,
        ]);
    }

    public function test_student_cannot_view_another_students_conversation(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::query()->create([
            'user_id' => $owner->id,
            'title' => '他人の会話',
        ]);

        $this->actingAs($otherStudent)
            ->get(route('ai-chat.conversations.show', $conversation))
            ->assertForbidden();
    }

    public function test_student_cannot_update_another_students_conversation(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::query()->create([
            'user_id' => $owner->id,
            'title' => '変更前のタイトル',
        ]);

        $this->actingAs($otherStudent)
            ->patch(route('ai-chat.conversations.update', $conversation), [
                'title' => '不正な変更',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
            'title' => '変更前のタイトル',
        ]);
    }

    public function test_student_cannot_delete_another_students_conversation(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::query()->create([
            'user_id' => $owner->id,
            'title' => '他人の会話',
        ]);

        $this->actingAs($otherStudent)
            ->delete(route('ai-chat.conversations.destroy', $conversation))
            ->assertForbidden();

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
        ]);
    }

    public function test_coach_cannot_access_ai_chat(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();

        $this->actingAs($coach)
            ->get(route('ai-chat.index'))
            ->assertForbidden();
    }

    public function test_admin_cannot_access_ai_chat(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();

        $this->actingAs($admin)
            ->get(route('ai-chat.index'))
            ->assertForbidden();
    }

    public function test_student_cannot_access_ai_chat_when_disabled(): void
    {
        config()->set('ai-chat.enabled', false);

        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->get(route('ai-chat.index'))
            ->assertForbidden();
    }

    public function test_conversation_creation_rejects_invalid_source(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->post(route('ai-chat.conversations.store'), [
                'source' => 'invalid',
            ])
            ->assertSessionHasErrors('source');

        $this->assertDatabaseCount('ai_chat_conversations', 0);
    }

    public function test_conversation_creation_rejects_too_long_message(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->post(route('ai-chat.conversations.store'), [
                'message' => str_repeat('あ', 4001),
            ])
            ->assertSessionHasErrors('message');

        $this->assertDatabaseCount('ai_chat_conversations', 0);
    }

    public function test_new_conversation_redirects_to_saved_conversation_when_gemini_fails(): void
    {
        $this->fakeGeminiUnavailable();

        $student = User::factory()->student()->inProgress()->create();
        $question = 'Laravelについて教えてください。';

        $response = $this->actingAs($student)
            ->post(route('ai-chat.conversations.store'), [
                'message' => $question,
            ]);

        $conversation = AiChatConversation::query()
            ->where('user_id', $student->id)
            ->firstOrFail();

        $response->assertRedirect(
            route('ai-chat.conversations.show', $conversation)
        );

        $this->assertFailedFirstMessageSaved($conversation, $question);

        Http::assertSentCount(1);
    }

    public function test_new_conversation_returns_502_and_saved_conversation_when_gemini_fails_for_json(): void
    {
        $this->fakeGeminiUnavailable();

        $student = User::factory()->student()->inProgress()->create();
        $question = 'Laravelについて教えてください。';

        $response = $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.store'), [
                'message' => $question,
            ]);

        $conversation = AiChatConversation::query()
            ->where('user_id', $student->id)
            ->firstOrFail();

        $response->assertStatus(502)
            ->assertJsonPath('conversation.id', $conversation->id);

        $this->assertFailedFirstMessageSaved($conversation, $question);

        Http::assertSentCount(1);
    }

    private function fakeGeminiUnavailable(): void
    {
        config()->set('ai-chat.gemini.api_key', 'test-api-key');
        config()->set('ai-chat.gemini.model', 'gemini-test-model');

        Http::preventStrayRequests();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 503,
                    'message' => 'Service unavailable',
                    'status' => 'UNAVAILABLE',
                ],
            ], 503),
        ]);
    }

    private function assertFailedFirstMessageSaved(
        AiChatConversation $conversation,
        string $question
    ): void {
        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
            'user_id' => $conversation->user_id,
        ]);

        $this->assertDatabaseHas('ai_chat_messages', [
            'conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::User->value,
            'content' => $question,
            'status' => AiChatMessageStatus::Completed->value,
        ]);

        $this->assertDatabaseHas('ai_chat_messages', [
            'conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::Assistant->value,
            'content' => null,
            'status' => AiChatMessageStatus::Error->value,
            'error_detail' => 'AIサービスから回答を取得できませんでした。',
        ]);
    }
}
