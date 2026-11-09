<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionQuestion;
use App\Models\User;
use App\Policies\SectionQuestionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SectionQuestionPolicy の判定を検証する Unit テスト。
 * admin / coach 担当・非担当・担当解除 / student 受講中 + Published のロール分岐を網羅する。
 */
class SectionQuestionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_full_access(): void
    {
        $admin = User::factory()->admin()->create();
        $question = SectionQuestion::factory()->published()->create();
        $section = $question->section;
        $policy = new SectionQuestionPolicy;

        $this->assertTrue($policy->viewAny($admin, $section));
        $this->assertTrue($policy->view($admin, $question));
        $this->assertTrue($policy->update($admin, $question));
    }

    public function test_student_with_enrollment_can_view_published_question(): void
    {
        $student = User::factory()->student()->create();
        $question = SectionQuestion::factory()->published()->create();
        $cert = $question->section->chapter->part->certification;
        Enrollment::factory()->for($student)->for($cert)->learning()->create();
        $policy = new SectionQuestionPolicy;

        $this->assertTrue($policy->view($student, $question));
    }

    public function test_student_without_enrollment_cannot_view(): void
    {
        $student = User::factory()->student()->create();
        $question = SectionQuestion::factory()->published()->create();
        $policy = new SectionQuestionPolicy;

        $this->assertFalse($policy->view($student, $question), '未受講の student は view 不可');
    }

    public function test_student_cannot_view_draft_question(): void
    {
        $student = User::factory()->student()->create();
        $question = SectionQuestion::factory()->draft()->create();
        $cert = $question->section->chapter->part->certification;
        Enrollment::factory()->for($student)->for($cert)->learning()->create();
        $policy = new SectionQuestionPolicy;

        $this->assertFalse($policy->view($student, $question), 'draft の question は受講中でも閲覧不可');
    }

    public function test_coach_assigned_only(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $assignedCert = Certification::factory()->published()->create();

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $assignedCert->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $assignedQuestion = SectionQuestion::factory()->published()->create([
            'section_id' => Section::factory()->state(fn () => [
                'chapter_id' => Chapter::factory()
                    ->for(Part::factory()->for($assignedCert))
                    ->create()->id,
            ]),
        ]);

        $policy = new SectionQuestionPolicy;

        $this->assertTrue($policy->update($coach, $assignedQuestion));
    }

    public function test_coach_can_manage_questions_for_assigned_certification(): void
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

        $part = Part::factory()->for($certification)->published()->create();
        $chapter = Chapter::factory()->for($part)->published()->create();
        $section = Section::factory()->for($chapter)->published()->create();
        $question = SectionQuestion::factory()->for($section)->draft()->create();
        $policy = new SectionQuestionPolicy;

        $this->assertTrue($policy->viewAny($coach, $section));
        $this->assertTrue($policy->view($coach, $question));
        $this->assertTrue($policy->create($coach, $section));
        $this->assertTrue($policy->update($coach, $question));
        $this->assertTrue($policy->delete($coach, $question));
        $this->assertTrue($policy->publish($coach, $question));
        $this->assertTrue($policy->unpublish($coach, $question));
    }

    public function test_coach_cannot_manage_questions_for_unassigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();

        $part = Part::factory()->for($certification)->published()->create();
        $chapter = Chapter::factory()->for($part)->published()->create();
        $section = Section::factory()->for($chapter)->published()->create();
        $question = SectionQuestion::factory()->for($section)->published()->create();
        $policy = new SectionQuestionPolicy;

        $this->assertFalse($policy->viewAny($coach, $section));
        $this->assertFalse($policy->view($coach, $question));
        $this->assertFalse($policy->create($coach, $section));
        $this->assertFalse($policy->update($coach, $question));
        $this->assertFalse($policy->delete($coach, $question));
        $this->assertFalse($policy->publish($coach, $question));
        $this->assertFalse($policy->unpublish($coach, $question));
    }

    public function test_coach_cannot_manage_questions_after_assignment_is_revoked(): void
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
        $chapter = Chapter::factory()->for($part)->published()->create();
        $section = Section::factory()->for($chapter)->published()->create();
        $question = SectionQuestion::factory()->for($section)->published()->create();
        $policy = new SectionQuestionPolicy;

        $this->assertFalse($policy->viewAny($coach, $section));
        $this->assertFalse($policy->view($coach, $question));
        $this->assertFalse($policy->create($coach, $section));
        $this->assertFalse($policy->update($coach, $question));
        $this->assertFalse($policy->delete($coach, $question));
        $this->assertFalse($policy->publish($coach, $question));
        $this->assertFalse($policy->unpublish($coach, $question));
    }
}
