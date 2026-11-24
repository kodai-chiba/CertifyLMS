<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\IndexAsCoachAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class IndexAsCoachActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_coachs_own_meetings(): void
    {
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $ownMeeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDays(2),
            ]);

        Meeting::factory()
            ->reserved()
            ->forCoach($otherCoach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDays(3),
            ]);

        $result = app(IndexAsCoachAction::class)($coach);

        $this->assertCount(1, $result->items());
        $this->assertTrue($result->first()->is($ownMeeting));
    }

    public function test_filters_upcoming_meetings_by_default(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $upcomingMeeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDays(2),
            ]);

        Meeting::factory()
            ->completed()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->subDays(2),
            ]);

        $result = app(IndexAsCoachAction::class)($coach);

        $this->assertCount(1, $result->items());
        $this->assertTrue($result->first()->is($upcomingMeeting));
    }

    public function test_filters_past_meetings(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $pastMeeting = Meeting::factory()
            ->completed()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->subDays(2),
            ]);

        Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDays(2),
            ]);

        $result = app(IndexAsCoachAction::class)($coach, 'past');

        $this->assertCount(1, $result->items());
        $this->assertTrue($result->first()->is($pastMeeting));
    }

    public function test_returns_upcoming_meetings_in_ascending_order(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $laterMeeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDays(5),
            ]);

        $earlierMeeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDays(2),
            ]);

        $result = app(IndexAsCoachAction::class)($coach);

        $this->assertCount(2, $result->items());
        $this->assertTrue($result->getCollection()[0]->is($earlierMeeting));
        $this->assertTrue($result->getCollection()[1]->is($laterMeeting));
    }

    public function test_returns_all_meetings_in_descending_order(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $olderMeeting = Meeting::factory()
            ->completed()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->subDays(3),
            ]);

        $newerMeeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDays(3),
            ]);

        $result = app(IndexAsCoachAction::class)($coach, 'all');

        $this->assertCount(2, $result->items());
        $this->assertTrue($result->getCollection()[0]->is($newerMeeting));
        $this->assertTrue($result->getCollection()[1]->is($olderMeeting));
    }

    public function test_filters_meetings_by_student(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();

        $targetMeeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDays(2),
            ]);

        Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($otherStudent)
            ->create([
                'scheduled_at' => now()->addDays(3),
            ]);

        $result = app(IndexAsCoachAction::class)(
            $coach,
            'all',
            (string) $student->id,
        );

        $this->assertCount(1, $result->items());
        $this->assertTrue($result->first()->is($targetMeeting));
    }

    public function test_filters_meetings_by_enrollment(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $targetMeeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDays(2),
            ]);

        $otherMeeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDays(3),
            ]);

        $result = app(IndexAsCoachAction::class)(
            $coach,
            'all',
            null,
            (string) $targetMeeting->enrollment_id,
        );

        $this->assertTrue(
            $result->getCollection()->every(
                fn (Meeting $meeting): bool => $meeting->enrollment_id === $targetMeeting->enrollment_id
            )
        );

        $this->assertTrue(
            $result->getCollection()->contains(
                fn (Meeting $meeting): bool => $meeting->is($targetMeeting)
            )
        );
    }

    public function test_paginates_meetings_twenty_per_page(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        Meeting::factory()
            ->count(21)
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->sequence(
                fn ($sequence) => [
                    'scheduled_at' => now()->addDays($sequence->index + 1),
                ],
            )
            ->create();

        $result = app(IndexAsCoachAction::class)($coach, 'all');

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(20, $result->perPage());
        $this->assertCount(20, $result->items());
        $this->assertSame(21, $result->total());
        $this->assertSame(2, $result->lastPage());
    }

    public function test_eager_loads_required_relations(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDays(2),
            ]);

        $result = app(IndexAsCoachAction::class)($coach);

        $meeting = $result->first();

        $this->assertTrue($meeting->relationLoaded('enrollment'));
        $this->assertTrue($meeting->enrollment->relationLoaded('certification'));
        $this->assertTrue($meeting->relationLoaded('student'));
    }
}
