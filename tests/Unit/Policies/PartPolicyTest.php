<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Part;
use App\Models\User;
use App\Policies\PartPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PartPolicy の判定を検証する Unit テスト。
 * admin / coach 担当・非担当・担当解除 / student published のロール分岐を網羅する。
 */
class PartPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_full_access(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->for($cert)->published()->create();
        $policy = new PartPolicy;

        $this->assertTrue($policy->viewAny($admin, $cert));
        $this->assertTrue($policy->view($admin, $part));
        $this->assertTrue($policy->update($admin, $part));
        $this->assertTrue($policy->delete($admin, $part));
    }

    public function test_coach_assigned_only(): void
    {
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
        $assignedPart = Part::factory()->for($assignedCert)->published()->create();
        $otherPart = Part::factory()->for($otherCert)->published()->create();
        $policy = new PartPolicy;

        $this->assertTrue($policy->update($coach, $assignedPart));
        $this->assertFalse($policy->update($coach, $otherPart));
    }

    public function test_student_view_published_only(): void
    {
        $student = User::factory()->student()->create();
        $publishedPart = Part::factory()->published()->create();
        $draftPart = Part::factory()->draft()->create();
        $policy = new PartPolicy;

        $this->assertTrue($policy->view($student, $publishedPart));
        $this->assertFalse($policy->view($student, $draftPart));
        $this->assertFalse($policy->update($student, $publishedPart));
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

        $part = Part::factory()->for($certification)->draft()->create();
        $policy = new PartPolicy;

        $this->assertTrue($policy->viewAny($coach, $certification));
        $this->assertTrue($policy->view($coach, $part));
        $this->assertTrue($policy->create($coach, $certification));
        $this->assertTrue($policy->update($coach, $part));
        $this->assertTrue($policy->delete($coach, $part));
        $this->assertTrue($policy->publish($coach, $part));
        $this->assertTrue($policy->unpublish($coach, $part));
        $this->assertTrue($policy->reorder($coach, $certification));
    }

    public function test_coach_cannot_perform_any_ability_for_unassigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $part = Part::factory()->for($certification)->published()->create();
        $policy = new PartPolicy;

        $this->assertFalse($policy->viewAny($coach, $certification));
        $this->assertFalse($policy->view($coach, $part));
        $this->assertFalse($policy->create($coach, $certification));
        $this->assertFalse($policy->update($coach, $part));
        $this->assertFalse($policy->delete($coach, $part));
        $this->assertFalse($policy->publish($coach, $part));
        $this->assertFalse($policy->unpublish($coach, $part));
        $this->assertFalse($policy->reorder($coach, $certification));
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

        $part = Part::factory()->for($certification)->published()->create();
        $policy = new PartPolicy;

        $this->assertFalse($policy->viewAny($coach, $certification));
        $this->assertFalse($policy->view($coach, $part));
        $this->assertFalse($policy->create($coach, $certification));
        $this->assertFalse($policy->update($coach, $part));
        $this->assertFalse($policy->delete($coach, $part));
        $this->assertFalse($policy->publish($coach, $part));
        $this->assertFalse($policy->unpublish($coach, $part));
        $this->assertFalse($policy->reorder($coach, $certification));
    }
}
