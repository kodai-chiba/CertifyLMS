<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Dashboard;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Plan;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Models\User;
use App\UseCases\Dashboard\FetchStudentDashboardAction;
use App\UseCases\Dashboard\ViewModels\StudentEnrollmentCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentDashboardProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_cards_receive_correct_progress_for_multiple_enrollments(): void
    {
        $student = $this->makeStudentWithPlan();

        $enrollment1 = $this->createEnrollment($student);
        $enrollment2 = $this->createEnrollment($student);
        $enrollment3 = $this->createEnrollment($student);

        $part1 = $this->createPart($enrollment1, 1);
        $chapter1 = $this->createChapter($part1, 1);

        $section1 = $this->createSection($chapter1, 1);
        $section2 = $this->createSection($chapter1, 2);
        $this->createSection($chapter1, 3);

        $this->completeSection($enrollment1, $section1);
        $this->completeSection($enrollment1, $section2);

        $part2 = $this->createPart($enrollment2, 1);
        $chapter2 = $this->createChapter($part2, 1);

        $section3 = $this->createSection($chapter2, 1);
        $section4 = $this->createSection($chapter2, 2);

        $this->completeSection($enrollment2, $section3);
        $this->completeSection($enrollment2, $section4);

        $part3 = $this->createPart($enrollment3, 1);
        $chapter3 = $this->createChapter($part3, 1);

        $this->createSection($chapter3, 1);

        $vm = app(FetchStudentDashboardAction::class)($student);

        $this->assertCount(3, $vm->enrollmentCards);

        $card1 = $vm->enrollmentCards->firstWhere('enrollmentId', $enrollment1->id);
        $card2 = $vm->enrollmentCards->firstWhere('enrollmentId', $enrollment2->id);
        $card3 = $vm->enrollmentCards->firstWhere('enrollmentId', $enrollment3->id);

        $this->assertInstanceOf(StudentEnrollmentCard::class, $card1);
        $this->assertInstanceOf(StudentEnrollmentCard::class, $card2);
        $this->assertInstanceOf(StudentEnrollmentCard::class, $card3);

        $this->assertSame(0.6667, $card1->progressRatio);
        $this->assertSame(1.0, $card2->progressRatio);
        $this->assertSame(0.0, $card3->progressRatio);
    }

    public function test_enrollment_card_receives_zero_progress_when_no_content_exists(): void
    {
        $student = $this->makeStudentWithPlan();
        $enrollment = $this->createEnrollment($student);

        $vm = app(FetchStudentDashboardAction::class)($student);

        $this->assertCount(1, $vm->enrollmentCards);

        $card = $vm->enrollmentCards->firstWhere('enrollmentId', $enrollment->id);

        $this->assertInstanceOf(StudentEnrollmentCard::class, $card);
        $this->assertSame(0.0, $card->progressRatio);
    }

    public function test_enrollment_card_progress_excludes_unpublished_content(): void
    {
        $student = $this->makeStudentWithPlan();
        $enrollment = $this->createEnrollment($student);

        $publishedPart = $this->createPart($enrollment, 1);
        $publishedChapter = $this->createChapter($publishedPart, 1);

        $publishedSection = $this->createSection($publishedChapter, 1);
        $this->createSection($publishedChapter, 2);

        $draftSection = Section::factory()
            ->draft()
            ->forChapter($publishedChapter)
            ->create(['order' => 3]);

        $draftChapter = Chapter::factory()
            ->draft()
            ->forPart($publishedPart)
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

        $vm = app(FetchStudentDashboardAction::class)($student);

        $this->assertCount(1, $vm->enrollmentCards);

        $card = $vm->enrollmentCards->firstWhere('enrollmentId', $enrollment->id);

        $this->assertInstanceOf(StudentEnrollmentCard::class, $card);
        $this->assertSame(0.5, $card->progressRatio);
    }

    public function test_enrollment_card_progress_excludes_other_students_completion_records(): void
    {
        $student = $this->makeStudentWithPlan();
        $anotherStudent = $this->makeStudentWithPlan();

        $enrollment = $this->createEnrollment($student);

        $anotherEnrollment = Enrollment::factory()
            ->for($anotherStudent)
            ->for($enrollment->certification)
            ->learning()
            ->create();

        $part = $this->createPart($enrollment, 1);
        $chapter = $this->createChapter($part, 1);

        $section1 = $this->createSection($chapter, 1);
        $section2 = $this->createSection($chapter, 2);

        $this->completeSection($enrollment, $section1);

        $this->completeSection($anotherEnrollment, $section1);
        $this->completeSection($anotherEnrollment, $section2);

        $vm = app(FetchStudentDashboardAction::class)($student);

        $this->assertCount(1, $vm->enrollmentCards);

        $card = $vm->enrollmentCards->firstWhere('enrollmentId', $enrollment->id);

        $this->assertInstanceOf(StudentEnrollmentCard::class, $card);
        $this->assertSame(0.5, $card->progressRatio);

        $this->assertNull(
            $vm->enrollmentCards->firstWhere('enrollmentId', $anotherEnrollment->id)
        );
    }

    private function makeStudentWithPlan(): User
    {
        $plan = Plan::factory()->published()->create();

        return User::factory()
            ->student()
            ->inProgress()
            ->withPlan($plan)
            ->create(['max_meetings' => 0]);
    }

    private function createEnrollment(User $student): Enrollment
    {
        $certification = Certification::factory()->published()->create();

        return Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->learning()
            ->create();
    }

    private function createPart(Enrollment $enrollment, int $order): Part
    {
        return Part::factory()
            ->published()
            ->forCertification($enrollment->certification)
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
