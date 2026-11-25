<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Enrollment;

use App\Models\CertificationCoachAssignment;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Models\User;
use App\Services\Learning\ProgressSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrollmentProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_passes_progress_summary_for_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->learning()->create();

        $this->createPartialProgress($enrollment);

        $response = $this->actingAs($admin)
            ->get(route('enrollments.show', $enrollment));

        $response->assertOk();
        $response->assertViewIs('enrollment.show');
        $response->assertViewHas('progress');

        $this->assertPartialProgress($response->viewData('progress'));
    }

    public function test_show_passes_progress_summary_for_assigned_coach(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->learning()->create();

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $enrollment->certification_id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $this->createPartialProgress($enrollment);

        $response = $this->actingAs($coach)
            ->get(route('enrollments.show', $enrollment));

        $response->assertOk();
        $response->assertViewIs('enrollment.show');
        $response->assertViewHas('progress');

        $this->assertPartialProgress($response->viewData('progress'));
    }

    public function test_show_preserves_null_progress_for_owner_student(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $this->createPartialProgress($enrollment);

        $response = $this->actingAs($student)
            ->get(route('enrollments.show', $enrollment));

        $response->assertOk();
        $response->assertViewIs('enrollment.show');
        $response->assertViewHas('progress');

        $this->assertNull($response->viewData('progress'));
    }

    private function createPartialProgress(Enrollment $enrollment): void
    {
        $part = Part::factory()
            ->published()
            ->forCertification($enrollment->certification)
            ->create(['order' => 1]);

        $chapter1 = Chapter::factory()
            ->published()
            ->forPart($part)
            ->create(['order' => 1]);

        $chapter2 = Chapter::factory()
            ->published()
            ->forPart($part)
            ->create(['order' => 2]);

        $section1 = Section::factory()
            ->published()
            ->forChapter($chapter1)
            ->create(['order' => 1]);

        $section2 = Section::factory()
            ->published()
            ->forChapter($chapter1)
            ->create(['order' => 2]);

        Section::factory()
            ->published()
            ->forChapter($chapter2)
            ->create(['order' => 1]);

        SectionProgress::factory()
            ->forEnrollment($enrollment)
            ->forSection($section1)
            ->completedNow()
            ->create();

        SectionProgress::factory()
            ->forEnrollment($enrollment)
            ->forSection($section2)
            ->completedNow()
            ->create();
    }

    private function assertPartialProgress(mixed $progress): void
    {
        $this->assertInstanceOf(ProgressSummary::class, $progress);

        $this->assertSame(3, $progress->sectionsTotal);
        $this->assertSame(2, $progress->sectionsCompleted);
        $this->assertSame(0.6667, $progress->sectionCompletionRatio);

        $this->assertSame(2, $progress->chaptersTotal);
        $this->assertSame(1, $progress->chaptersCompleted);
        $this->assertSame(0.5, $progress->chapterCompletionRatio);

        $this->assertSame(1, $progress->partsTotal);
        $this->assertSame(0, $progress->partsCompleted);
        $this->assertSame(0.0, $progress->partCompletionRatio);

        $this->assertSame(0.6667, $progress->overallCompletionRatio);
    }
}
