<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Services\CompletionEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompletionEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private CompletionEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CompletionEligibilityService::class);
    }

    public function test_returns_false_when_no_published_mock_exam_exists(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertFalse($this->service->isEligible($enrollment));
    }

    public function test_returns_false_when_only_unpublished_mock_exams_exist(): void
    {
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($certification)->create();

        MockExam::factory()->for($certification)->create([
            'is_published' => false,
        ]);

        $this->assertFalse($this->service->isEligible($enrollment));
    }

    public function test_returns_false_when_not_all_published_mock_exams_passed(): void
    {
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($certification)->create();

        $exam1 = MockExam::factory()->for($certification)->create([
            'is_published' => true,
        ]);

        MockExam::factory()->for($certification)->create([
            'is_published' => true,
        ]);

        MockExamSession::factory()->for($enrollment)->for($exam1)->create([
            'pass' => true,
        ]);

        $this->assertFalse($this->service->isEligible($enrollment));
    }

    public function test_returns_true_when_all_published_mock_exams_have_passing_session(): void
    {
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($certification)->create();

        $exam1 = MockExam::factory()->for($certification)->create([
            'is_published' => true,
        ]);

        $exam2 = MockExam::factory()->for($certification)->create([
            'is_published' => true,
        ]);

        MockExamSession::factory()->for($enrollment)->for($exam1)->create([
            'pass' => true,
        ]);

        MockExamSession::factory()->for($enrollment)->for($exam2)->create([
            'pass' => true,
        ]);

        $this->assertTrue($this->service->isEligible($enrollment));
    }

    public function test_returns_true_when_same_exam_has_multiple_passing_sessions(): void
    {
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($certification)->create();

        $exam = MockExam::factory()->for($certification)->create([
            'is_published' => true,
        ]);

        MockExamSession::factory()->for($enrollment)->for($exam)->create([
            'pass' => true,
        ]);

        MockExamSession::factory()->for($enrollment)->for($exam)->create([
            'pass' => true,
        ]);

        $this->assertTrue($this->service->isEligible($enrollment));
    }

    public function test_unpublished_exam_pass_does_not_count_toward_completion(): void
    {
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($certification)->create();

        $publishedExam1 = MockExam::factory()->for($certification)->create([
            'is_published' => true,
        ]);

        MockExam::factory()->for($certification)->create([
            'is_published' => true,
        ]);

        $unpublishedExam = MockExam::factory()->for($certification)->create([
            'is_published' => false,
        ]);

        MockExamSession::factory()->for($enrollment)->for($publishedExam1)->create([
            'pass' => true,
        ]);

        MockExamSession::factory()->for($enrollment)->for($unpublishedExam)->create([
            'pass' => true,
        ]);

        $this->assertFalse($this->service->isEligible($enrollment));
    }

    public function test_another_certifications_exam_pass_does_not_count_toward_completion(): void
    {
        $certification = Certification::factory()->published()->create();
        $anotherCertification = Certification::factory()->published()->create();

        $enrollment = Enrollment::factory()->for($certification)->create();

        $publishedExam1 = MockExam::factory()->for($certification)->create([
            'is_published' => true,
        ]);

        MockExam::factory()->for($certification)->create([
            'is_published' => true,
        ]);

        $anotherExam = MockExam::factory()->for($anotherCertification)->create([
            'is_published' => true,
        ]);

        MockExamSession::factory()->for($enrollment)->for($publishedExam1)->create([
            'pass' => true,
        ]);

        MockExamSession::factory()->for($enrollment)->for($anotherExam)->create([
            'pass' => true,
        ]);

        $this->assertFalse($this->service->isEligible($enrollment));
    }

    public function test_duplicate_passing_sessions_do_not_replace_another_unpassed_exam(): void
    {
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($certification)->create();

        $exam1 = MockExam::factory()->for($certification)->create([
            'is_published' => true,
        ]);

        MockExam::factory()->for($certification)->create([
            'is_published' => true,
        ]);

        MockExamSession::factory()->for($enrollment)->for($exam1)->create([
            'pass' => true,
        ]);

        MockExamSession::factory()->for($enrollment)->for($exam1)->create([
            'pass' => true,
        ]);

        $this->assertFalse($this->service->isEligible($enrollment));
    }
}
