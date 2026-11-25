<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Learning;

use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Models\User;
use App\Services\Learning\ProgressSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrowseProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_enrollment_passes_progress_summary_at_each_level(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $part = $this->createPart($enrollment);
        $chapter1 = $this->createChapter($part, 1);
        $chapter2 = $this->createChapter($part, 2);

        $section1 = $this->createSection($chapter1, 1);
        $section2 = $this->createSection($chapter1, 2);
        $this->createSection($chapter2, 1);

        $this->completeSection($enrollment, $section1);
        $this->completeSection($enrollment, $section2);

        $response = $this->actingAs($student)
            ->get(route('learning.enrollments.show', $enrollment));

        $response->assertOk();
        $response->assertViewIs('learning.enrollments.show');
        $response->assertViewHas('progress');

        $progress = $response->viewData('progress');

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

    public function test_show_enrollment_passes_zero_progress_when_no_content_exists(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $response = $this->actingAs($student)
            ->get(route('learning.enrollments.show', $enrollment));

        $response->assertOk();
        $response->assertViewIs('learning.enrollments.show');
        $response->assertViewHas('progress');

        $progress = $response->viewData('progress');

        $this->assertInstanceOf(ProgressSummary::class, $progress);

        $this->assertSame(0, $progress->sectionsTotal);
        $this->assertSame(0, $progress->sectionsCompleted);
        $this->assertSame(0.0, $progress->sectionCompletionRatio);

        $this->assertSame(0, $progress->chaptersTotal);
        $this->assertSame(0, $progress->chaptersCompleted);
        $this->assertSame(0.0, $progress->chapterCompletionRatio);

        $this->assertSame(0, $progress->partsTotal);
        $this->assertSame(0, $progress->partsCompleted);
        $this->assertSame(0.0, $progress->partCompletionRatio);

        $this->assertSame(0.0, $progress->overallCompletionRatio);
    }

    public function test_show_part_passes_section_totals_and_completed_counts_by_chapter(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $part = $this->createPart($enrollment);

        $chapter1 = $this->createChapter($part, 1);
        $chapter2 = $this->createChapter($part, 2);
        $chapter3 = $this->createChapter($part, 3);

        $section1 = $this->createSection($chapter1, 1);
        $section2 = $this->createSection($chapter1, 2);

        $section3 = $this->createSection($chapter2, 1);
        $this->createSection($chapter2, 2);

        $this->createSection($chapter3, 1);

        $this->completeSection($enrollment, $section1);
        $this->completeSection($enrollment, $section2);
        $this->completeSection($enrollment, $section3);

        $response = $this->actingAs($student)
            ->get(route('learning.parts.show', $part));

        $response->assertOk();
        $response->assertViewHas('chapters');
        $response->assertViewHas('completedByChapter');

        $chapters = $response->viewData('chapters')->keyBy('id');
        $completedByChapter = $response->viewData('completedByChapter');

        $this->assertCount(3, $chapters);

        $this->assertSame(2, (int) $chapters[$chapter1->id]->sections_total_count);
        $this->assertSame(2, (int) $chapters[$chapter2->id]->sections_total_count);
        $this->assertSame(1, (int) $chapters[$chapter3->id]->sections_total_count);

        $this->assertCount(2, $completedByChapter);
        $this->assertSame(2, $completedByChapter[$chapter1->id]);
        $this->assertSame(1, $completedByChapter[$chapter2->id]);
        $this->assertArrayNotHasKey($chapter3->id, $completedByChapter);
    }

    public function test_show_part_excludes_unpublished_chapters_and_sections_from_progress(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $part = $this->createPart($enrollment);

        $publishedChapter = $this->createChapter($part, 1);

        $publishedSection = $this->createSection($publishedChapter, 1);
        $this->createSection($publishedChapter, 2);

        $draftSection = Section::factory()
            ->draft()
            ->forChapter($publishedChapter)
            ->create(['order' => 3]);

        $draftChapter = Chapter::factory()
            ->draft()
            ->forPart($part)
            ->create(['order' => 2]);

        $sectionUnderDraftChapter = $this->createSection($draftChapter, 1);

        $this->completeSection($enrollment, $publishedSection);
        $this->completeSection($enrollment, $draftSection);
        $this->completeSection($enrollment, $sectionUnderDraftChapter);

        $response = $this->actingAs($student)
            ->get(route('learning.parts.show', $part));

        $response->assertOk();
        $response->assertViewHas('chapters');
        $response->assertViewHas('completedByChapter');

        $chapters = $response->viewData('chapters')->keyBy('id');
        $completedByChapter = $response->viewData('completedByChapter');

        $this->assertCount(1, $chapters);
        $this->assertTrue($chapters->has($publishedChapter->id));
        $this->assertFalse($chapters->has($draftChapter->id));

        $this->assertSame(
            2,
            (int) $chapters[$publishedChapter->id]->sections_total_count
        );

        $this->assertSame([
            $publishedChapter->id => 1,
        ], $completedByChapter);
    }

    private function createPart(Enrollment $enrollment): Part
    {
        return Part::factory()
            ->published()
            ->forCertification($enrollment->certification)
            ->create(['order' => 1]);
    }

    private function createChapter(Part $part, int $order): Chapter
    {
        return Chapter::factory()
            ->published()
            ->forPart($part)
            ->create(['order' => $order]);
    }

    private function createSection(Chapter $chapter, int $order): Section
    {
        return Section::factory()
            ->published()
            ->forChapter($chapter)
            ->create(['order' => $order]);
    }

    private function completeSection(Enrollment $enrollment, Section $section): void
    {
        SectionProgress::factory()
            ->forEnrollment($enrollment)
            ->forSection($section)
            ->completedNow()
            ->create();
    }
}
