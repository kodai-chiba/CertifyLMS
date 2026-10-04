<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 質問掲示板の主要な HTTP フローを検証する Feature テスト。
 *
 * Route / Middleware / FormRequest / Policy / Controller / UseCase を通して、
 * student・coach・admin の主要操作が正しく連携することを確認する。
 */
class QaBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_view_qa_board(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        QaThread::factory()
            ->for(Certification::factory()->published())
            ->create([
                'title' => '表示確認用の質問',
            ]);

        $this->actingAs($student)
            ->get(route('qa-board.index'))
            ->assertOk()
            ->assertSee('表示確認用の質問');
    }

    public function test_student_can_create_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $response = $this->actingAs($student)
            ->post(route('qa-board.store'), [
                'certification_id' => $certification->id,
                'title' => '新しい質問',
                'body' => '質問の本文です。',
            ]);

        $thread = QaThread::query()
            ->where('user_id', $student->id)
            ->where('certification_id', $certification->id)
            ->firstOrFail();

        $response->assertRedirect(route('qa-board.show', $thread));

        $this->assertSame('新しい質問', $thread->title);
        $this->assertSame('質問の本文です。', $thread->body);
        $this->assertSame(QaThreadStatus::Unresolved, $thread->status);
        $this->assertNull($thread->resolved_at);
    }

    public function test_thread_store_validation_rejects_too_long_title(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $response = $this->actingAs($student)
            ->from(route('qa-board.create'))
            ->post(route('qa-board.store'), [
                'certification_id' => $certification->id,
                'title' => str_repeat('あ', 201),
                'body' => '質問本文',
            ]);

        $response
            ->assertRedirect(route('qa-board.create'))
            ->assertSessionHasErrors('title');

        $this->assertDatabaseCount('qa_threads', 0);
    }

    public function test_student_can_update_own_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $thread = QaThread::factory()
            ->for(Certification::factory()->published())
            ->for($student)
            ->create();

        $response = $this->actingAs($student)
            ->patch(route('qa-board.update', $thread), [
                'title' => '更新後のタイトル',
                'body' => '更新後の本文',
            ]);

        $response->assertRedirect(route('qa-board.show', $thread));

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'title' => '更新後のタイトル',
            'body' => '更新後の本文',
        ]);
    }

    public function test_student_can_resolve_and_unresolve_own_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $thread = QaThread::factory()
            ->for(Certification::factory()->published())
            ->for($student)
            ->create();

        $this->actingAs($student)
            ->post(route('qa-board.resolve', $thread))
            ->assertRedirect(route('qa-board.show', $thread));

        $thread->refresh();

        $this->assertSame(QaThreadStatus::Resolved, $thread->status);
        $this->assertNotNull($thread->resolved_at);

        $this->actingAs($student)
            ->post(route('qa-board.unresolve', $thread))
            ->assertRedirect(route('qa-board.show', $thread));

        $thread->refresh();

        $this->assertSame(QaThreadStatus::Unresolved, $thread->status);
        $this->assertNull($thread->resolved_at);
    }

    public function test_student_can_create_update_and_delete_own_reply(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $thread = QaThread::factory()
            ->for(Certification::factory()->published())
            ->create();

        $response = $this->actingAs($student)
            ->post(route('qa-board.replies.store', $thread), [
                'body' => '最初の回答です。',
            ]);

        $response->assertRedirect(route('qa-board.show', $thread));

        $reply = QaReply::query()
            ->where('qa_thread_id', $thread->id)
            ->where('user_id', $student->id)
            ->firstOrFail();

        $this->actingAs($student)
            ->patch(route('qa-board.replies.update', [$thread, $reply]), [
                'body' => '編集後の回答です。',
            ])
            ->assertRedirect(route('qa-board.show', $thread));

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'body' => '編集後の回答です。',
        ]);

        $this->actingAs($student)
            ->delete(route('qa-board.replies.destroy', [$thread, $reply]))
            ->assertRedirect(route('qa-board.show', $thread));

        $this->assertDatabaseMissing('qa_replies', [
            'id' => $reply->id,
        ]);
    }

    public function test_coach_can_view_assigned_thread_but_cannot_view_unassigned_thread(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();

        $assignedCertification = Certification::factory()->published()->create();
        $unassignedCertification = Certification::factory()->published()->create();

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $assignedCertification->id,
            'user_id' => $coach->id,
        ]);

        $assignedThread = QaThread::factory()
            ->for($assignedCertification)
            ->create();

        $unassignedThread = QaThread::factory()
            ->for($unassignedCertification)
            ->create();

        $this->actingAs($coach)
            ->get(route('qa-board.show', $assignedThread))
            ->assertOk();

        $this->actingAs($coach)
            ->get(route('qa-board.show', $unassignedThread))
            ->assertForbidden();
    }

    public function test_coach_can_reply_to_assigned_thread(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
        ]);

        $thread = QaThread::factory()
            ->for($certification)
            ->create();

        $this->actingAs($coach)
            ->post(route('qa-board.replies.store', $thread), [
                'body' => 'コーチからの回答です。',
            ])
            ->assertRedirect(route('qa-board.show', $thread));

        $this->assertDatabaseHas('qa_replies', [
            'qa_thread_id' => $thread->id,
            'user_id' => $coach->id,
            'body' => 'コーチからの回答です。',
        ]);
    }

    public function test_admin_can_view_unpublished_thread_on_management_board(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();

        $thread = QaThread::factory()
            ->for(Certification::factory()->draft())
            ->create([
                'title' => '非公開資格の質問',
            ]);

        $this->actingAs($admin)
            ->get(route('admin.qa-board.show', $thread))
            ->assertOk()
            ->assertSee('非公開資格の質問');
    }

    public function test_admin_can_delete_thread_and_its_replies(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();

        $thread = QaThread::factory()
            ->for(Certification::factory()->published())
            ->create();

        $reply = QaReply::factory()
            ->for($thread, 'thread')
            ->create();

        $this->actingAs($admin)
            ->delete(route('admin.qa-board.destroy', $thread))
            ->assertRedirect(route('admin.qa-board.index'));

        $this->assertDatabaseMissing('qa_threads', [
            'id' => $thread->id,
        ]);

        $this->assertDatabaseMissing('qa_replies', [
            'id' => $reply->id,
        ]);
    }

    public function test_admin_can_delete_reply(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();

        $thread = QaThread::factory()
            ->for(Certification::factory()->published())
            ->create();

        $reply = QaReply::factory()
            ->for($thread, 'thread')
            ->create();

        $this->actingAs($admin)
            ->delete(route('admin.qa-board.replies.destroy', [$thread, $reply]))
            ->assertRedirect(route('admin.qa-board.show', $thread));

        $this->assertDatabaseMissing('qa_replies', [
            'id' => $reply->id,
        ]);

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
        ]);
    }

    public function test_admin_cannot_access_public_qa_board_routes(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();

        $this->actingAs($admin)
            ->get(route('qa-board.index'))
            ->assertForbidden();
    }

    public function test_student_cannot_access_admin_qa_board_routes(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->get(route('admin.qa-board.index'))
            ->assertForbidden();
    }
}
