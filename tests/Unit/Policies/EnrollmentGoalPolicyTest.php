<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use App\Policies\EnrollmentGoalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EnrollmentGoalPolicy の判定を検証する Unit テスト。
 *
 * 個人学習目標の作成・更新・削除・達成・未達成への変更は、
 * 対象 Enrollment の受講生本人のみ許可する。
 * Coach / Admin / 他受講生による変更は拒否する。
 */
class EnrollmentGoalPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_student_can_create_goal_for_own_enrollment(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $policy = new EnrollmentGoalPolicy;

        // Act & Assert
        $this->assertTrue($policy->create($student, $enrollment));
    }

    public function test_other_student_cannot_create_goal_for_another_enrollment(): void
    {
        // Arrange
        $owner = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($owner)->create();
        $policy = new EnrollmentGoalPolicy;

        // Act & Assert
        $this->assertFalse($policy->create($other, $enrollment));
    }

    public function test_coach_and_admin_cannot_create_goal(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $policy = new EnrollmentGoalPolicy;

        // Act & Assert
        $this->assertFalse($policy->create($coach, $enrollment));
        $this->assertFalse($policy->create($admin, $enrollment));
    }

    public function test_owner_student_can_update_delete_and_change_achievement_state(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $policy = new EnrollmentGoalPolicy;

        // Act & Assert
        $this->assertTrue($policy->update($student, $goal));
        $this->assertTrue($policy->delete($student, $goal));
        $this->assertTrue($policy->markAchieved($student, $goal));
        $this->assertTrue($policy->unmarkAchieved($student, $goal));
    }

    public function test_other_student_cannot_update_delete_or_change_achievement_state(): void
    {
        // Arrange
        $owner = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($owner)->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $policy = new EnrollmentGoalPolicy;

        // Act & Assert
        $this->assertFalse($policy->update($other, $goal));
        $this->assertFalse($policy->delete($other, $goal));
        $this->assertFalse($policy->markAchieved($other, $goal));
        $this->assertFalse($policy->unmarkAchieved($other, $goal));
    }

    public function test_coach_cannot_update_delete_or_change_achievement_state(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $policy = new EnrollmentGoalPolicy;

        // Act & Assert
        $this->assertFalse($policy->update($coach, $goal));
        $this->assertFalse($policy->delete($coach, $goal));
        $this->assertFalse($policy->markAchieved($coach, $goal));
        $this->assertFalse($policy->unmarkAchieved($coach, $goal));
    }

    public function test_admin_cannot_update_delete_or_change_achievement_state(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $policy = new EnrollmentGoalPolicy;

        // Act & Assert
        $this->assertFalse($policy->update($admin, $goal));
        $this->assertFalse($policy->delete($admin, $goal));
        $this->assertFalse($policy->markAchieved($admin, $goal));
        $this->assertFalse($policy->unmarkAchieved($admin, $goal));
    }
}
