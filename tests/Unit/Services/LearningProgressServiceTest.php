<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Services\Learning\LearningProgressService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearningProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    private LearningProgressService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(LearningProgressService::class);
    }

    public function test_summarize_progress_calculates_partial_completion_at_each_level(): void
    {
        $enrollment = Enrollment::factory()->create();
        $certification = $enrollment->certification;

        $part1 = $this->createPart($certification, 1);
        $part2 = $this->createPart($certification, 2);

        $chapter1 = $this->createChapter($part1, 1);
        $chapter2 = $this->createChapter($part2, 1);

        $section1 = $this->createSection($chapter1, 1);
        $section2 = $this->createSection($chapter1, 2);
        $this->createSection($chapter2, 1);

        $this->completeSection($enrollment, $section1);
        $this->completeSection($enrollment, $section2);

        $summary = $this->service->summarizeProgress($enrollment);

        $this->assertSame(3, $summary->sectionsTotal);
        $this->assertSame(2, $summary->sectionsCompleted);
        $this->assertSame(0.6667, $summary->sectionCompletionRatio);

        $this->assertSame(2, $summary->chaptersTotal);
        $this->assertSame(1, $summary->chaptersCompleted);
        $this->assertSame(0.5, $summary->chapterCompletionRatio);

        $this->assertSame(2, $summary->partsTotal);
        $this->assertSame(1, $summary->partsCompleted);
        $this->assertSame(0.5, $summary->partCompletionRatio);

        $this->assertSame(0.6667, $summary->overallCompletionRatio);
    }

    public function test_summarize_progress_returns_full_completion_when_all_sections_are_completed(): void
    {
        $enrollment = Enrollment::factory()->create();

        $part = $this->createPart($enrollment->certification, 1);
        $chapter = $this->createChapter($part, 1);

        $section1 = $this->createSection($chapter, 1);
        $section2 = $this->createSection($chapter, 2);

        $this->completeSection($enrollment, $section1);
        $this->completeSection($enrollment, $section2);

        $summary = $this->service->summarizeProgress($enrollment);

        $this->assertSame(2, $summary->sectionsTotal);
        $this->assertSame(2, $summary->sectionsCompleted);
        $this->assertSame(1.0, $summary->sectionCompletionRatio);

        $this->assertSame(1, $summary->chaptersTotal);
        $this->assertSame(1, $summary->chaptersCompleted);
        $this->assertSame(1.0, $summary->chapterCompletionRatio);

        $this->assertSame(1, $summary->partsTotal);
        $this->assertSame(1, $summary->partsCompleted);
        $this->assertSame(1.0, $summary->partCompletionRatio);

        $this->assertSame(1.0, $summary->overallCompletionRatio);
    }

    public function test_summarize_progress_returns_zero_completion_when_no_sections_are_completed(): void
    {
        $enrollment = Enrollment::factory()->create();

        $part = $this->createPart($enrollment->certification, 1);
        $chapter = $this->createChapter($part, 1);

        $this->createSection($chapter, 1);
        $this->createSection($chapter, 2);

        $summary = $this->service->summarizeProgress($enrollment);

        $this->assertSame(2, $summary->sectionsTotal);
        $this->assertSame(0, $summary->sectionsCompleted);
        $this->assertSame(0.0, $summary->sectionCompletionRatio);

        $this->assertSame(1, $summary->chaptersTotal);
        $this->assertSame(0, $summary->chaptersCompleted);
        $this->assertSame(0.0, $summary->chapterCompletionRatio);

        $this->assertSame(1, $summary->partsTotal);
        $this->assertSame(0, $summary->partsCompleted);
        $this->assertSame(0.0, $summary->partCompletionRatio);

        $this->assertSame(0.0, $summary->overallCompletionRatio);
    }

    public function test_summarize_progress_returns_zero_totals_when_no_content_exists(): void
    {
        $enrollment = Enrollment::factory()->create();

        $summary = $this->service->summarizeProgress($enrollment);

        $this->assertSame(0, $summary->sectionsTotal);
        $this->assertSame(0, $summary->sectionsCompleted);
        $this->assertSame(0.0, $summary->sectionCompletionRatio);

        $this->assertSame(0, $summary->chaptersTotal);
        $this->assertSame(0, $summary->chaptersCompleted);
        $this->assertSame(0.0, $summary->chapterCompletionRatio);

        $this->assertSame(0, $summary->partsTotal);
        $this->assertSame(0, $summary->partsCompleted);
        $this->assertSame(0.0, $summary->partCompletionRatio);

        $this->assertSame(0.0, $summary->overallCompletionRatio);
    }

    public function test_summarize_progress_excludes_unpublished_parts_and_their_descendants(): void
    {
        $enrollment = Enrollment::factory()->create();

        $publishedPart = $this->createPart($enrollment->certification, 1);
        $publishedChapter = $this->createChapter($publishedPart, 1);
        $publishedSection = $this->createSection($publishedChapter, 1);

        $draftPart = Part::factory()
            ->draft()
            ->forCertification($enrollment->certification)
            ->create(['order' => 2]);

        $chapterUnderDraftPart = $this->createChapter($draftPart, 1);
        $sectionUnderDraftPart = $this->createSection($chapterUnderDraftPart, 1);

        $this->completeSection($enrollment, $publishedSection);
        $this->completeSection($enrollment, $sectionUnderDraftPart);

        $summary = $this->service->summarizeProgress($enrollment);

        $this->assertSame(1, $summary->sectionsTotal);
        $this->assertSame(1, $summary->sectionsCompleted);
        $this->assertSame(1, $summary->chaptersTotal);
        $this->assertSame(1, $summary->chaptersCompleted);
        $this->assertSame(1, $summary->partsTotal);
        $this->assertSame(1, $summary->partsCompleted);
        $this->assertSame(1.0, $summary->overallCompletionRatio);
    }

    public function test_summarize_progress_excludes_unpublished_chapters_and_their_sections(): void
    {
        $enrollment = Enrollment::factory()->create();

        $part = $this->createPart($enrollment->certification, 1);

        $publishedChapter = $this->createChapter($part, 1);
        $publishedSection = $this->createSection($publishedChapter, 1);

        $draftChapter = Chapter::factory()
            ->draft()
            ->forPart($part)
            ->create(['order' => 2]);

        $sectionUnderDraftChapter = $this->createSection($draftChapter, 1);

        $this->completeSection($enrollment, $publishedSection);
        $this->completeSection($enrollment, $sectionUnderDraftChapter);

        $summary = $this->service->summarizeProgress($enrollment);

        $this->assertSame(1, $summary->sectionsTotal);
        $this->assertSame(1, $summary->sectionsCompleted);
        $this->assertSame(1, $summary->chaptersTotal);
        $this->assertSame(1, $summary->chaptersCompleted);
        $this->assertSame(1, $summary->partsTotal);
        $this->assertSame(1, $summary->partsCompleted);
        $this->assertSame(1.0, $summary->overallCompletionRatio);
    }

    public function test_summarize_progress_excludes_unpublished_sections(): void
    {
        $enrollment = Enrollment::factory()->create();

        $part = $this->createPart($enrollment->certification, 1);
        $chapter = $this->createChapter($part, 1);

        $publishedSection = $this->createSection($chapter, 1);

        $draftSection = Section::factory()
            ->draft()
            ->forChapter($chapter)
            ->create(['order' => 2]);

        $this->completeSection($enrollment, $publishedSection);
        $this->completeSection($enrollment, $draftSection);

        $summary = $this->service->summarizeProgress($enrollment);

        $this->assertSame(1, $summary->sectionsTotal);
        $this->assertSame(1, $summary->sectionsCompleted);
        $this->assertSame(1, $summary->chaptersCompleted);
        $this->assertSame(1, $summary->partsCompleted);
        $this->assertSame(1.0, $summary->overallCompletionRatio);
    }

    public function test_summarize_progress_excludes_other_enrollments_completion_records(): void
    {
        $enrollment = Enrollment::factory()->create();

        $anotherEnrollment = Enrollment::factory()->create([
            'certification_id' => $enrollment->certification_id,
        ]);

        $part = $this->createPart($enrollment->certification, 1);
        $chapter = $this->createChapter($part, 1);

        $section1 = $this->createSection($chapter, 1);
        $section2 = $this->createSection($chapter, 2);

        $this->completeSection($enrollment, $section1);
        $this->completeSection($anotherEnrollment, $section2);

        $summary = $this->service->summarizeProgress($enrollment);

        $this->assertSame(2, $summary->sectionsTotal);
        $this->assertSame(1, $summary->sectionsCompleted);
        $this->assertSame(0, $summary->chaptersCompleted);
        $this->assertSame(0, $summary->partsCompleted);
        $this->assertSame(0.5, $summary->overallCompletionRatio);
    }

    public function test_summarize_progress_excludes_content_from_other_certifications(): void
    {
        $enrollment = Enrollment::factory()->create();
        $anotherEnrollment = Enrollment::factory()->create();

        $part = $this->createPart($enrollment->certification, 1);
        $chapter = $this->createChapter($part, 1);
        $this->createSection($chapter, 1);

        $anotherPart = $this->createPart($anotherEnrollment->certification, 1);
        $anotherChapter = $this->createChapter($anotherPart, 1);
        $anotherSection = $this->createSection($anotherChapter, 1);

        $this->completeSection($anotherEnrollment, $anotherSection);

        $summary = $this->service->summarizeProgress($enrollment);

        $this->assertSame(1, $summary->sectionsTotal);
        $this->assertSame(0, $summary->sectionsCompleted);
        $this->assertSame(1, $summary->chaptersTotal);
        $this->assertSame(1, $summary->partsTotal);
        $this->assertSame(0.0, $summary->overallCompletionRatio);
    }

    public function test_summarize_progress_does_not_count_empty_chapters_and_parts_as_completed(): void
    {
        $enrollment = Enrollment::factory()->create();

        $partWithEmptyChapter = $this->createPart($enrollment->certification, 1);
        $this->createChapter($partWithEmptyChapter, 1);

        $this->createPart($enrollment->certification, 2);

        $summary = $this->service->summarizeProgress($enrollment);

        $this->assertSame(0, $summary->sectionsTotal);
        $this->assertSame(0, $summary->sectionsCompleted);

        $this->assertSame(1, $summary->chaptersTotal);
        $this->assertSame(0, $summary->chaptersCompleted);
        $this->assertSame(0.0, $summary->chapterCompletionRatio);

        $this->assertSame(2, $summary->partsTotal);
        $this->assertSame(0, $summary->partsCompleted);
        $this->assertSame(0.0, $summary->partCompletionRatio);

        $this->assertSame(0.0, $summary->overallCompletionRatio);
    }

    public function test_summarize_progress_preserves_part_completion_when_an_empty_chapter_exists(): void
    {
        $enrollment = Enrollment::factory()->create();

        $part = $this->createPart($enrollment->certification, 1);

        $chapter = $this->createChapter($part, 1);
        $section = $this->createSection($chapter, 1);

        $this->createChapter($part, 2);

        $this->completeSection($enrollment, $section);

        $summary = $this->service->summarizeProgress($enrollment);

        $this->assertSame(1, $summary->sectionsTotal);
        $this->assertSame(1, $summary->sectionsCompleted);

        $this->assertSame(2, $summary->chaptersTotal);
        $this->assertSame(1, $summary->chaptersCompleted);
        $this->assertSame(0.5, $summary->chapterCompletionRatio);

        $this->assertSame(1, $summary->partsTotal);
        $this->assertSame(1, $summary->partsCompleted);
        $this->assertSame(1.0, $summary->partCompletionRatio);
        $this->assertSame(1.0, $summary->overallCompletionRatio);
    }

    public function test_summarize_progress_rounds_completion_ratios_to_four_decimal_places(): void
    {
        $enrollment = Enrollment::factory()->create();

        for ($order = 1; $order <= 3; $order++) {
            $part = $this->createPart($enrollment->certification, $order);
            $chapter = $this->createChapter($part, 1);
            $section = $this->createSection($chapter, 1);

            if ($order === 1) {
                $this->completeSection($enrollment, $section);
            }
        }

        $summary = $this->service->summarizeProgress($enrollment);

        $this->assertSame(0.3333, $summary->sectionCompletionRatio);
        $this->assertSame(0.3333, $summary->chapterCompletionRatio);
        $this->assertSame(0.3333, $summary->partCompletionRatio);
        $this->assertSame(0.3333, $summary->overallCompletionRatio);
    }

    public function test_batch_calculate_progress_returns_ratios_for_multiple_enrollments(): void
    {
        $enrollment1 = Enrollment::factory()->create();

        $enrollment2 = Enrollment::factory()->create([
            'certification_id' => $enrollment1->certification_id,
        ]);

        $enrollment3 = Enrollment::factory()->create();

        $part1 = $this->createPart($enrollment1->certification, 1);
        $chapter1 = $this->createChapter($part1, 1);

        $section1 = $this->createSection($chapter1, 1);
        $section2 = $this->createSection($chapter1, 2);
        $section3 = $this->createSection($chapter1, 3);

        $this->completeSection($enrollment1, $section1);

        $this->completeSection($enrollment2, $section1);
        $this->completeSection($enrollment2, $section2);
        $this->completeSection($enrollment2, $section3);

        $part2 = $this->createPart($enrollment3->certification, 1);
        $chapter2 = $this->createChapter($part2, 1);
        $this->createSection($chapter2, 1);

        $result = $this->service->batchCalculateProgress(
            new Collection([$enrollment1, $enrollment2, $enrollment3])
        );

        $this->assertSame([
            $enrollment1->id => 0.3333,
            $enrollment2->id => 1.0,
            $enrollment3->id => 0.0,
        ], $result);
    }

    public function test_batch_calculate_progress_returns_zero_when_no_content_exists(): void
    {
        $enrollment = Enrollment::factory()->create();

        $result = $this->service->batchCalculateProgress(
            new Collection([$enrollment])
        );

        $this->assertSame([
            $enrollment->id => 0.0,
        ], $result);
    }

    public function test_batch_calculate_progress_excludes_unpublished_content(): void
    {
        $enrollment = Enrollment::factory()->create();

        $part = $this->createPart($enrollment->certification, 1);
        $chapter = $this->createChapter($part, 1);

        $publishedSection = $this->createSection($chapter, 1);
        $this->createSection($chapter, 2);

        $draftSection = Section::factory()
            ->draft()
            ->forChapter($chapter)
            ->create(['order' => 3]);

        $draftChapter = Chapter::factory()
            ->draft()
            ->forPart($part)
            ->create(['order' => 2]);

        $sectionUnderDraftChapter = $this->createSection($draftChapter, 1);

        $draftPart = Part::factory()
            ->draft()
            ->forCertification($enrollment->certification)
            ->create(['order' => 2]);

        $chapterUnderDraftPart = $this->createChapter($draftPart, 1);
        $sectionUnderDraftPart = $this->createSection($chapterUnderDraftPart, 1);

        $this->completeSection($enrollment, $publishedSection);
        $this->completeSection($enrollment, $draftSection);
        $this->completeSection($enrollment, $sectionUnderDraftChapter);
        $this->completeSection($enrollment, $sectionUnderDraftPart);

        $result = $this->service->batchCalculateProgress(
            new Collection([$enrollment])
        );

        $this->assertSame([
            $enrollment->id => 0.5,
        ], $result);
    }

    public function test_batch_calculate_progress_returns_empty_array_without_queries_for_empty_collection(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $result = $this->service->batchCalculateProgress(new Collection);

            $queryCount = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame([], $result);
        $this->assertSame(0, $queryCount);
    }

    public function test_batch_calculate_progress_uses_one_query_for_multiple_enrollments(): void
    {
        $enrollment1 = Enrollment::factory()->create();

        $enrollment2 = Enrollment::factory()->create([
            'certification_id' => $enrollment1->certification_id,
        ]);

        $part = $this->createPart($enrollment1->certification, 1);
        $chapter = $this->createChapter($part, 1);
        $section = $this->createSection($chapter, 1);

        $this->completeSection($enrollment1, $section);

        $enrollments = new Collection([$enrollment1, $enrollment2]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $result = $this->service->batchCalculateProgress($enrollments);

            $queryCount = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame(1, $queryCount);
        $this->assertSame([
            $enrollment1->id => 1.0,
            $enrollment2->id => 0.0,
        ], $result);
    }

    public function test_count_completed_sections_by_chapter_returns_counts_for_requested_chapters(): void
    {
        $enrollment = Enrollment::factory()->create();

        $part = $this->createPart($enrollment->certification, 1);

        $chapter1 = $this->createChapter($part, 1);
        $chapter2 = $this->createChapter($part, 2);
        $chapter3 = $this->createChapter($part, 3);
        $chapter4 = $this->createChapter($part, 4);

        $section1 = $this->createSection($chapter1, 1);
        $section2 = $this->createSection($chapter1, 2);
        $section3 = $this->createSection($chapter2, 1);
        $this->createSection($chapter2, 2);
        $this->createSection($chapter3, 1);
        $section4 = $this->createSection($chapter4, 1);

        $this->completeSection($enrollment, $section1);
        $this->completeSection($enrollment, $section2);
        $this->completeSection($enrollment, $section3);
        $this->completeSection($enrollment, $section4);

        $result = $this->service->countCompletedSectionsByChapter(
            $enrollment,
            new Collection([$chapter1, $chapter2, $chapter3])
        );

        $this->assertCount(2, $result);
        $this->assertSame(2, $result[$chapter1->id]);
        $this->assertSame(1, $result[$chapter2->id]);
        $this->assertArrayNotHasKey($chapter3->id, $result);
        $this->assertArrayNotHasKey($chapter4->id, $result);
    }

    public function test_count_completed_sections_by_chapter_excludes_unpublished_sections(): void
    {
        $enrollment = Enrollment::factory()->create();

        $part = $this->createPart($enrollment->certification, 1);
        $chapter = $this->createChapter($part, 1);

        $publishedSection = $this->createSection($chapter, 1);

        $draftSection = Section::factory()
            ->draft()
            ->forChapter($chapter)
            ->create(['order' => 2]);

        $this->completeSection($enrollment, $publishedSection);
        $this->completeSection($enrollment, $draftSection);

        $result = $this->service->countCompletedSectionsByChapter(
            $enrollment,
            new Collection([$chapter])
        );

        $this->assertSame([
            $chapter->id => 1,
        ], $result);
    }

    public function test_count_completed_sections_by_chapter_excludes_other_enrollments_records(): void
    {
        $enrollment = Enrollment::factory()->create();

        $anotherEnrollment = Enrollment::factory()->create([
            'certification_id' => $enrollment->certification_id,
        ]);

        $part = $this->createPart($enrollment->certification, 1);
        $chapter = $this->createChapter($part, 1);

        $section1 = $this->createSection($chapter, 1);
        $section2 = $this->createSection($chapter, 2);

        $this->completeSection($enrollment, $section1);
        $this->completeSection($anotherEnrollment, $section2);

        $result = $this->service->countCompletedSectionsByChapter(
            $enrollment,
            new Collection([$chapter])
        );

        $this->assertSame([
            $chapter->id => 1,
        ], $result);
    }

    public function test_count_completed_sections_by_chapter_returns_empty_array_when_no_sections_are_completed(): void
    {
        $enrollment = Enrollment::factory()->create();

        $part = $this->createPart($enrollment->certification, 1);
        $chapter = $this->createChapter($part, 1);

        $this->createSection($chapter, 1);

        $result = $this->service->countCompletedSectionsByChapter(
            $enrollment,
            new Collection([$chapter])
        );

        $this->assertSame([], $result);
    }

    public function test_count_completed_sections_by_chapter_returns_empty_array_without_queries_when_enrollment_is_null(): void
    {
        $enrollment = Enrollment::factory()->create();

        $part = $this->createPart($enrollment->certification, 1);
        $chapter = $this->createChapter($part, 1);

        $chapters = new Collection([$chapter]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $result = $this->service->countCompletedSectionsByChapter(
                null,
                $chapters
            );

            $queryCount = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame([], $result);
        $this->assertSame(0, $queryCount);
    }

    public function test_count_completed_sections_by_chapter_returns_empty_array_without_queries_when_chapters_are_empty(): void
    {
        $enrollment = Enrollment::factory()->create();

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $result = $this->service->countCompletedSectionsByChapter(
                $enrollment,
                new Collection
            );

            $queryCount = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame([], $result);
        $this->assertSame(0, $queryCount);
    }

    private function createPart(Certification $certification, int $order): Part
    {
        return Part::factory()
            ->published()
            ->forCertification($certification)
            ->create(['order' => $order]);
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
