<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\IndexAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class IndexActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_students_own_meetings(): void
    {
        $student = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        $ownMeeting = Meeting::factory()
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

        $result = app(IndexAction::class)($student);

        $this->assertCount(1, $result->items());
        $this->assertTrue($result->first()->is($ownMeeting));
    }

    public function test_filters_upcoming_meetings_by_default(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

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

        $result = app(IndexAction::class)($student);

        $this->assertCount(1, $result->items());
        $this->assertTrue($result->first()->is($upcomingMeeting));
    }

    public function test_filters_past_meetings(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

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

        $result = app(IndexAction::class)($student, 'past');

        $this->assertCount(1, $result->items());
        $this->assertTrue($result->first()->is($pastMeeting));
    }

    public function test_returns_all_meetings_in_descending_order(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

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

        $result = app(IndexAction::class)($student, 'all');

        $this->assertCount(2, $result->items());
        $this->assertTrue($result->getCollection()[0]->is($newerMeeting));
        $this->assertTrue($result->getCollection()[1]->is($olderMeeting));
    }

    public function test_paginates_meetings_twenty_per_page(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

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

        $result = app(IndexAction::class)($student, 'all');

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(20, $result->perPage());
        $this->assertCount(20, $result->items());
        $this->assertSame(21, $result->total());
        $this->assertSame(2, $result->lastPage());
    }

    public function test_eager_loads_required_relations(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDay(),
            ]);

        $result = app(IndexAction::class)($student);

        $meeting = $result->first();

        $this->assertTrue($meeting->relationLoaded('enrollment'));
        $this->assertTrue($meeting->enrollment->relationLoaded('certification'));
        $this->assertTrue($meeting->relationLoaded('coach'));
    }
}
