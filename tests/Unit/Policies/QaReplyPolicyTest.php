<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Policies\QaReplyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaReplyPolicy の認可判定を検証する Unit テスト。
 *
 * student / coach / admin のロール分岐、
 * 資格の公開状態、coach の担当状態、回答投稿者本人による
 * 編集・削除の権限制御を検証する。
 */
class QaReplyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_reply_to_published_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()
            ->for(Certification::factory()->published())
            ->create();

        $this->assertTrue((new QaReplyPolicy)->create($student, $thread));
    }

    public function test_student_cannot_reply_to_unpublished_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()
            ->for(Certification::factory()->draft())
            ->create();

        $this->assertFalse((new QaReplyPolicy)->create($student, $thread));
    }

    public function test_coach_can_reply_only_to_assigned_published_certification_thread(): void
    {
        $coach = User::factory()->coach()->create();

        $assignedCertification = Certification::factory()->published()->create();

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $assignedCertification->id,
            'user_id' => $coach->id,
        ]);

        $unassignedCertification = Certification::factory()->published()->create();

        $assignedThread = QaThread::factory()
            ->for($assignedCertification)
            ->create();

        $unassignedThread = QaThread::factory()
            ->for($unassignedCertification)
            ->create();

        $policy = new QaReplyPolicy;

        $this->assertTrue($policy->create($coach, $assignedThread));
        $this->assertFalse($policy->create($coach, $unassignedThread));
    }

    public function test_admin_cannot_reply(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()
            ->for(Certification::factory()->published())
            ->create();

        $this->assertFalse((new QaReplyPolicy)->create($admin, $thread));
    }

    public function test_student_can_update_and_delete_own_reply_but_not_other_users_reply(): void
    {
        $student = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();

        $thread = QaThread::factory()
            ->for(Certification::factory()->published())
            ->create();

        $ownReply = QaReply::factory()
            ->for($thread, 'thread')
            ->for($student)
            ->create();

        $otherReply = QaReply::factory()
            ->for($thread, 'thread')
            ->for($otherStudent)
            ->create();

        $policy = new QaReplyPolicy;

        $this->assertTrue($policy->update($student, $ownReply));
        $this->assertTrue($policy->delete($student, $ownReply));

        $this->assertFalse($policy->update($student, $otherReply));
        $this->assertFalse($policy->delete($student, $otherReply));
    }

    public function test_student_cannot_update_or_delete_own_reply_after_certification_becomes_unpublished(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification)
            ->create();

        $reply = QaReply::factory()
            ->for($thread, 'thread')
            ->for($student)
            ->create();

        $certification->update([
            'status' => 'draft',
        ]);

        $policy = new QaReplyPolicy;

        $this->assertFalse($policy->update($student, $reply));
        $this->assertFalse($policy->delete($student, $reply));
    }

    public function test_coach_can_update_and_delete_own_reply_while_assigned(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
        ]);

        $thread = QaThread::factory()
            ->for($certification)
            ->create();

        $reply = QaReply::factory()
            ->for($thread, 'thread')
            ->for($coach)
            ->create();

        $policy = new QaReplyPolicy;

        $this->assertTrue($policy->update($coach, $reply));
        $this->assertTrue($policy->delete($coach, $reply));
    }

    public function test_coach_cannot_update_or_delete_own_reply_after_unassignment(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();

        $assignment = CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
        ]);

        $thread = QaThread::factory()
            ->for($certification)
            ->create();

        $reply = QaReply::factory()
            ->for($thread, 'thread')
            ->for($coach)
            ->create();

        $assignment->update([
            'unassigned_at' => now(),
        ]);

        $policy = new QaReplyPolicy;

        $this->assertFalse($policy->update($coach, $reply));
        $this->assertFalse($policy->delete($coach, $reply));
    }

    public function test_admin_can_delete_any_reply_but_cannot_update_it(): void
    {
        $admin = User::factory()->admin()->create();

        $thread = QaThread::factory()
            ->for(Certification::factory()->draft())
            ->create();

        $reply = QaReply::factory()
            ->for($thread, 'thread')
            ->create();

        $policy = new QaReplyPolicy;

        $this->assertFalse($policy->update($admin, $reply));
        $this->assertTrue($policy->delete($admin, $reply));
    }
}
