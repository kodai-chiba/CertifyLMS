<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Enrollment;
use App\Models\User;
use App\UseCases\Meeting\CreateFallbackAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateFallbackActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_learning_and_passed_enrollments(): void
    {
        $student = User::factory()->student()->create();

        $learning = Enrollment::factory()
            ->for($student, 'user')
            ->learning()
            ->create();

        $passed = Enrollment::factory()
            ->for($student, 'user')
            ->passed()
            ->create();

        $failed = Enrollment::factory()
            ->for($student, 'user')
            ->failed()
            ->create();

        $enrollments = app(CreateFallbackAction::class)($student);

        $this->assertCount(2, $enrollments);

        $this->assertEqualsCanonicalizing(
            [$learning->id, $passed->id],
            $enrollments->modelKeys()
        );

        $this->assertNotContains($failed->id, $enrollments->modelKeys());
    }

    public function test_excludes_other_students_enrollments(): void
    {
        $student = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();

        $ownEnrollment = Enrollment::factory()
            ->for($student, 'user')
            ->learning()
            ->create();

        Enrollment::factory()
            ->for($otherStudent, 'user')
            ->learning()
            ->create();

        $enrollments = app(CreateFallbackAction::class)($student);

        $this->assertCount(1, $enrollments);
        $this->assertSame($ownEnrollment->id, $enrollments->first()->id);
    }

    public function test_eager_loads_certification(): void
    {
        $student = User::factory()->student()->create();

        Enrollment::factory()
            ->for($student, 'user')
            ->learning()
            ->create();

        $enrollments = app(CreateFallbackAction::class)($student);

        $this->assertCount(1, $enrollments);
        $this->assertTrue(
            $enrollments->first()->relationLoaded('certification')
        );
    }

    public function test_returns_empty_collection_when_no_matching_enrollments(): void
    {
        $student = User::factory()->student()->create();

        Enrollment::factory()
            ->for($student, 'user')
            ->failed()
            ->create();

        $enrollments = app(CreateFallbackAction::class)($student);

        $this->assertCount(0, $enrollments);
    }

    public function test_returns_empty_collection_for_null_user(): void
    {
        $enrollments = app(CreateFallbackAction::class)(null);

        $this->assertCount(0, $enrollments);
    }
}
