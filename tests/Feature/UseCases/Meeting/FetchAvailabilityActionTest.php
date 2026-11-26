<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\UseCases\Meeting\FetchAvailabilityAction;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class FetchAvailabilityActionTest extends TestCase
{
    use RefreshDatabase;

    private function attachCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }

    public function test_returns_date_and_available_slots(): void
    {
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->create();

        $this->attachCoach($certification, $coach);

        CoachAvailability::factory()
            ->forCoach($coach)
            ->onDay(1)
            ->timeRange('09:00:00', '12:00:00')
            ->create();

        $date = Carbon::parse('2026-06-01');

        $result = app(FetchAvailabilityAction::class)($enrollment, $date);

        $this->assertSame('2026-06-01', $result['date']);
        $this->assertCount(3, $result['slots']);

        $this->assertSame(
            '2026-06-01T09:00:00+09:00',
            $result['slots'][0]['slot_start'],
        );

        $this->assertSame(
            '2026-06-01T10:00:00+09:00',
            $result['slots'][0]['slot_end'],
        );

        $this->assertSame(1, $result['slots'][0]['available_coach_count']);
    }

    public function test_returns_multiple_coaches_available_count(): void
    {
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();
        $coachA = User::factory()->coach()->create();
        $coachB = User::factory()->coach()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->create();

        $this->attachCoach($certification, $coachA);
        $this->attachCoach($certification, $coachB);

        CoachAvailability::factory()
            ->forCoach($coachA)
            ->onDay(1)
            ->timeRange('09:00:00', '10:00:00')
            ->create();

        CoachAvailability::factory()
            ->forCoach($coachB)
            ->onDay(1)
            ->timeRange('09:00:00', '10:00:00')
            ->create();

        $date = Carbon::parse('2026-06-01');

        $result = app(FetchAvailabilityAction::class)($enrollment, $date);

        $this->assertCount(1, $result['slots']);
        $this->assertSame(2, $result['slots'][0]['available_coach_count']);
    }

    public function test_returns_empty_slots_when_no_availability_exists(): void
    {
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->create();

        $date = Carbon::parse('2026-06-01');

        $result = app(FetchAvailabilityAction::class)($enrollment, $date);

        $this->assertSame('2026-06-01', $result['date']);
        $this->assertSame([], $result['slots']);
    }

    public function test_excludes_inactive_availability(): void
    {
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->create();

        $this->attachCoach($certification, $coach);

        CoachAvailability::factory()
            ->forCoach($coach)
            ->onDay(1)
            ->timeRange('09:00:00', '11:00:00')
            ->inactive()
            ->create();

        $date = Carbon::parse('2026-06-01');

        $result = app(FetchAvailabilityAction::class)($enrollment, $date);

        $this->assertSame([], $result['slots']);
    }

    /**
     * @group external-api
     * @group google-calendar
     */
    public function test_returns_slots_and_coach_counts_reflecting_google_calendar_availability(): void
    {
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();
        $coachA = User::factory()->coach()->create();
        $coachB = User::factory()->coach()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->create();

        $this->attachCoach($certification, $coachA);
        $this->attachCoach($certification, $coachB);

        CoachAvailability::factory()
            ->forCoach($coachA)
            ->onDay(1)
            ->timeRange('09:00:00', '12:00:00')
            ->create();

        CoachAvailability::factory()
            ->forCoach($coachB)
            ->onDay(1)
            ->timeRange('09:00:00', '12:00:00')
            ->create();

        $googleCalendar = Mockery::mock(GoogleCalendarService::class);

        $googleCalendar->shouldReceive('isBusy')
            ->once()
            ->withArgs(fn (User $user, CarbonInterface $start, CarbonInterface $end): bool => $user->is($coachA)
                && $start->toIso8601String() === '2026-06-01T09:00:00+09:00'
                && $end->toIso8601String() === '2026-06-01T10:00:00+09:00'
            )
            ->andReturnTrue();

        $googleCalendar->shouldReceive('isBusy')
            ->once()
            ->withArgs(fn (User $user, CarbonInterface $start, CarbonInterface $end): bool => $user->is($coachB)
                && $start->toIso8601String() === '2026-06-01T09:00:00+09:00'
                && $end->toIso8601String() === '2026-06-01T10:00:00+09:00'
            )
            ->andReturnFalse();

        $googleCalendar->shouldReceive('isBusy')
            ->once()
            ->withArgs(fn (User $user, CarbonInterface $start, CarbonInterface $end): bool => $user->is($coachA)
                && $start->toIso8601String() === '2026-06-01T10:00:00+09:00'
                && $end->toIso8601String() === '2026-06-01T11:00:00+09:00'
            )
            ->andReturnTrue();

        $googleCalendar->shouldReceive('isBusy')
            ->once()
            ->withArgs(fn (User $user, CarbonInterface $start, CarbonInterface $end): bool => $user->is($coachB)
                && $start->toIso8601String() === '2026-06-01T10:00:00+09:00'
                && $end->toIso8601String() === '2026-06-01T11:00:00+09:00'
            )
            ->andReturnTrue();

        $googleCalendar->shouldReceive('isBusy')
            ->once()
            ->withArgs(fn (User $user, CarbonInterface $start, CarbonInterface $end): bool => $user->is($coachA)
                && $start->toIso8601String() === '2026-06-01T11:00:00+09:00'
                && $end->toIso8601String() === '2026-06-01T12:00:00+09:00'
            )
            ->andReturnFalse();

        $googleCalendar->shouldReceive('isBusy')
            ->once()
            ->withArgs(fn (User $user, CarbonInterface $start, CarbonInterface $end): bool => $user->is($coachB)
                && $start->toIso8601String() === '2026-06-01T11:00:00+09:00'
                && $end->toIso8601String() === '2026-06-01T12:00:00+09:00'
            )
            ->andReturnFalse();

        $this->app->instance(GoogleCalendarService::class, $googleCalendar);

        $date = Carbon::parse('2026-06-01');

        $result = app(FetchAvailabilityAction::class)($enrollment, $date);

        $this->assertSame('2026-06-01', $result['date']);
        $this->assertCount(2, $result['slots']);

        $this->assertSame(
            '2026-06-01T09:00:00+09:00',
            $result['slots'][0]['slot_start'],
        );

        $this->assertSame(
            '2026-06-01T10:00:00+09:00',
            $result['slots'][0]['slot_end'],
        );

        $this->assertSame(1, $result['slots'][0]['available_coach_count']);

        $this->assertSame(
            '2026-06-01T11:00:00+09:00',
            $result['slots'][1]['slot_start'],
        );

        $this->assertSame(
            '2026-06-01T12:00:00+09:00',
            $result['slots'][1]['slot_end'],
        );

        $this->assertSame(2, $result['slots'][1]['available_coach_count']);
    }
}
