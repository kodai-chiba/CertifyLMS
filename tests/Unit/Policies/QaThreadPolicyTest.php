<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Policies\QaThreadPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaThreadPolicy の認可判定を検証する Unit テスト。
 *
 * student / coach / admin のロール分岐、
 * 公開状態、投稿者本人、回答有無、解決状態による権限制御を検証する。
 */
class QaThreadPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_view_published_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()
            ->for(Certification::factory()->published())
            ->create();

        $this->assertTrue((new QaThreadPolicy)->view($student, $thread));
    }

    public function test_student_cannot_view_unpublished_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()
            ->for(Certification::factory()->draft())
            ->create();

        $this->assertFalse((new QaThreadPolicy)->view($student, $thread));
    }

    public function test_student_can_create_thread(): void
    {
        $student = User::factory()->student()->create();

        $this->assertTrue((new QaThreadPolicy)->create($student));
    }

    public function test_student_can_update_own_thread_but_not_other_users_thread(): void
    {
        $student = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $ownThread = QaThread::factory()
            ->for($certification)
            ->for($student)
            ->create();

        $otherThread = QaThread::factory()
            ->for($certification)
            ->for($otherStudent)
            ->create();

        $policy = new QaThreadPolicy;

        $this->assertTrue($policy->update($student, $ownThread));
        $this->assertFalse($policy->update($student, $otherThread));
    }

    public function test_student_can_delete_own_thread_when_it_has_no_replies(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()
            ->for(Certification::factory()->published())
            ->for($student)
            ->create();

        $this->assertTrue((new QaThreadPolicy)->delete($student, $thread));
    }

    public function test_student_cannot_delete_own_thread_when_it_has_replies(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()
            ->for(Certification::factory()->published())
            ->for($student)
            ->create();

        QaReply::factory()
            ->for($thread, 'thread')
            ->create();

        $this->assertFalse((new QaThreadPolicy)->delete($student, $thread));
    }

    public function test_student_can_resolve_own_unresolved_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()
            ->for(Certification::factory()->published())
            ->for($student)
            ->create();

        $this->assertTrue((new QaThreadPolicy)->resolve($student, $thread));
        $this->assertFalse((new QaThreadPolicy)->unresolve($student, $thread));
    }

    public function test_student_can_unresolve_own_resolved_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()
            ->resolved()
            ->for(Certification::factory()->published())
            ->for($student)
            ->create();

        $this->assertTrue((new QaThreadPolicy)->unresolve($student, $thread));
        $this->assertFalse((new QaThreadPolicy)->resolve($student, $thread));
    }

    public function test_coach_can_view_only_assigned_published_certification_thread(): void
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

        $policy = new QaThreadPolicy;

        $this->assertTrue($policy->view($coach, $assignedThread));
        $this->assertFalse($policy->view($coach, $unassignedThread));
    }

    public function test_coach_cannot_view_assigned_unpublished_certification_thread(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->draft()->create();

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
        ]);

        $thread = QaThread::factory()
            ->for($certification)
            ->create();

        $this->assertFalse((new QaThreadPolicy)->view($coach, $thread));
    }

    public function test_admin_can_view_and_delete_unpublished_thread(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()
            ->for(Certification::factory()->draft())
            ->create();

        QaReply::factory()
            ->for($thread, 'thread')
            ->create();

        $policy = new QaThreadPolicy;

        $this->assertTrue($policy->view($admin, $thread));
        $this->assertTrue($policy->delete($admin, $thread));
    }

    public function test_admin_cannot_create_update_or_change_resolution_status(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()
            ->for(Certification::factory()->published())
            ->create();

        $this->assertFalse((new QaThreadPolicy)->create($admin));
        $this->assertFalse((new QaThreadPolicy)->update($admin, $thread));
        $this->assertFalse((new QaThreadPolicy)->resolve($admin, $thread));
        $this->assertFalse((new QaThreadPolicy)->unresolve($admin, $thread));
    }
}
