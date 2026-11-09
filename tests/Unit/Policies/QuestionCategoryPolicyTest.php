<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QuestionCategory;
use App\Models\User;
use App\Policies\QuestionCategoryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * QuestionCategoryPolicy の判定を検証する Unit テスト。
 * 4 ability (viewAny / create / update / delete) × admin (全可) / coach (担当のみ) / student (全不可) を網羅する。
 */
class QuestionCategoryPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_perform_all_abilities(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $category = QuestionCategory::factory()->for($cert)->create();
        $policy = new QuestionCategoryPolicy;

        // Act & Assert
        $this->assertTrue($policy->viewAny($admin, $cert));
        $this->assertTrue($policy->create($admin, $cert));
        $this->assertTrue($policy->update($admin, $category));
        $this->assertTrue($policy->delete($admin, $category));
    }

    public function test_coach_can_manage_only_assigned_certification(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $assignedCert = Certification::factory()->published()->create();
        $otherCert = Certification::factory()->published()->create();

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $assignedCert->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $assignedCategory = QuestionCategory::factory()->for($assignedCert)->create();
        $otherCategory = QuestionCategory::factory()->for($otherCert)->create();
        $policy = new QuestionCategoryPolicy;

        // Act & Assert
        $this->assertTrue($policy->update($coach, $assignedCategory), 'coach は担当資格の category を更新できるはず');
        $this->assertFalse($policy->update($coach, $otherCategory), '非担当資格の category は更新できないはず');
    }

    public function test_student_cannot_manage_any_category(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        $category = QuestionCategory::factory()->for($cert)->create();
        $policy = new QuestionCategoryPolicy;

        // Act & Assert
        $this->assertFalse($policy->viewAny($student, $cert));
        $this->assertFalse($policy->create($student, $cert));
        $this->assertFalse($policy->update($student, $category));
        $this->assertFalse($policy->delete($student, $category));
    }

    public function test_coach_can_perform_all_abilities_for_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $category = QuestionCategory::factory()->for($certification)->create();
        $policy = new QuestionCategoryPolicy;

        $this->assertTrue($policy->viewAny($coach, $certification));
        $this->assertTrue($policy->create($coach, $certification));
        $this->assertTrue($policy->update($coach, $category));
        $this->assertTrue($policy->delete($coach, $category));
    }

    public function test_coach_cannot_perform_any_ability_for_unassigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $category = QuestionCategory::factory()->for($certification)->create();
        $policy = new QuestionCategoryPolicy;

        $this->assertFalse($policy->viewAny($coach, $certification));
        $this->assertFalse($policy->create($coach, $certification));
        $this->assertFalse($policy->update($coach, $category));
        $this->assertFalse($policy->delete($coach, $category));
    }

    public function test_coach_cannot_manage_certification_after_assignment_is_revoked(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now()->subDay(),
            'unassigned_at' => now(),
        ]);

        $category = QuestionCategory::factory()->for($certification)->create();
        $policy = new QuestionCategoryPolicy;

        $this->assertFalse($policy->viewAny($coach, $certification));
        $this->assertFalse($policy->create($coach, $certification));
        $this->assertFalse($policy->update($coach, $category));
        $this->assertFalse($policy->delete($coach, $category));
    }
}
