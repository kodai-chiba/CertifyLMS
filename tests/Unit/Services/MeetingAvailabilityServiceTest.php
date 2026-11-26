<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\Mentoring\MeetingOutOfAvailabilityException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\MeetingAvailabilityService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MeetingAvailabilityServiceTest extends TestCase
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

    public function test_returns_60min_slots_for_active_availability(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);

        $date = Carbon::parse('2026-06-01'); // Monday (dayOfWeek=1)
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        // 09-10 / 10-11 / 11-12 の 3 枠
        $this->assertCount(3, $slots);
        $this->assertSame('2026-06-01T09:00:00+09:00', $slots->first()['slot_start']->toIso8601String());
        $this->assertSame(1, $slots->first()['available_coach_count']);
    }

    public function test_excludes_existing_reserved_meetings(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $this->attachCoach($certification, $coach);

        $date = Carbon::parse('2026-06-01');
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();
        // 10:00 にすでに予約あり
        Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => Carbon::parse('2026-06-01 10:00:00'),
        ]);

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        $times = $slots->map(fn (array $s) => $s['slot_start']->format('H:i'))->all();
        $this->assertEquals(['09:00', '11:00'], $times);
    }

    public function test_excludes_inactive_availability(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);

        $date = Carbon::parse('2026-06-01');
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '11:00:00')->inactive()->create();

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        $this->assertCount(0, $slots);
    }

    public function test_unions_multiple_coaches_into_available_count(): void
    {
        $certification = Certification::factory()->published()->create();
        $coachA = User::factory()->coach()->create();
        $coachB = User::factory()->coach()->create();
        $this->attachCoach($certification, $coachA);
        $this->attachCoach($certification, $coachB);

        $date = Carbon::parse('2026-06-01');
        CoachAvailability::factory()->forCoach($coachA)->onDay(1)->timeRange('09:00:00', '10:00:00')->create();
        CoachAvailability::factory()->forCoach($coachB)->onDay(1)->timeRange('09:00:00', '10:00:00')->create();

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        $this->assertCount(1, $slots);
        $this->assertSame(2, $slots->first()['available_coach_count']);
    }

    public function test_validate_slot_throws_when_outside_availability(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '10:00:00')->create();

        $this->expectException(MeetingOutOfAvailabilityException::class);

        // 月曜の枠は 09-10 のみ。15:00 は枠外
        app(MeetingAvailabilityService::class)->validateSlot($certification, Carbon::parse('2026-06-01 15:00:00'));
    }

    public function test_validate_slot_succeeds_when_in_availability(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();

        // 例外が起きないことを確認
        app(MeetingAvailabilityService::class)->validateSlot($certification, Carbon::parse('2026-06-01 09:00:00'));
        $this->addToAssertionCount(1);
    }

    /**
     * @group external-api
     * @group google-calendar
     */
    public function test_excludes_slots_when_google_calendar_is_busy(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);

        CoachAvailability::factory()
            ->forCoach($coach)
            ->onDay(1)
            ->timeRange('09:00:00', '12:00:00')
            ->create();

        $googleCalendar = Mockery::mock(GoogleCalendarService::class);

        $googleCalendar->shouldReceive('isBusy')
            ->once()
            ->withArgs(fn (User $user, CarbonInterface $start, CarbonInterface $end): bool => $user->is($coach)
                && $start->toIso8601String() === '2026-06-01T09:00:00+09:00'
                && $end->toIso8601String() === '2026-06-01T10:00:00+09:00'
            )
            ->andReturnFalse();

        $googleCalendar->shouldReceive('isBusy')
            ->once()
            ->withArgs(fn (User $user, CarbonInterface $start, CarbonInterface $end): bool => $user->is($coach)
                && $start->toIso8601String() === '2026-06-01T10:00:00+09:00'
                && $end->toIso8601String() === '2026-06-01T11:00:00+09:00'
            )
            ->andReturnTrue();

        $googleCalendar->shouldReceive('isBusy')
            ->once()
            ->withArgs(fn (User $user, CarbonInterface $start, CarbonInterface $end): bool => $user->is($coach)
                && $start->toIso8601String() === '2026-06-01T11:00:00+09:00'
                && $end->toIso8601String() === '2026-06-01T12:00:00+09:00'
            )
            ->andReturnFalse();

        $this->app->instance(GoogleCalendarService::class, $googleCalendar);

        $slots = app(MeetingAvailabilityService::class)
            ->slotsForCertification($certification, Carbon::parse('2026-06-01'));

        $this->assertCount(2, $slots);

        $this->assertSame(
            ['09:00', '11:00'],
            $slots->map(fn (array $slot) => $slot['slot_start']->format('H:i'))->all()
        );

        $this->assertSame(
            [1, 1],
            $slots->pluck('available_coach_count')->all()
        );
    }

    /**
     * @group external-api
     * @group google-calendar
     */
    public function test_counts_only_coaches_without_google_calendar_conflicts(): void
    {
        $certification = Certification::factory()->published()->create();
        $coachA = User::factory()->coach()->create();
        $coachB = User::factory()->coach()->create();

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

        $googleCalendar = Mockery::mock(GoogleCalendarService::class);

        $googleCalendar->shouldReceive('isBusy')
            ->once()
            ->withArgs(fn (User $user, CarbonInterface $start, CarbonInterface $end): bool => $user->is($coachA)
                && $start->format('H:i') === '09:00'
                && $end->format('H:i') === '10:00'
            )
            ->andReturnTrue();

        $googleCalendar->shouldReceive('isBusy')
            ->once()
            ->withArgs(fn (User $user, CarbonInterface $start, CarbonInterface $end): bool => $user->is($coachB)
                && $start->format('H:i') === '09:00'
                && $end->format('H:i') === '10:00'
            )
            ->andReturnFalse();

        $this->app->instance(GoogleCalendarService::class, $googleCalendar);

        $slots = app(MeetingAvailabilityService::class)
            ->slotsForCertification($certification, Carbon::parse('2026-06-01'));

        $this->assertCount(1, $slots);
        $this->assertSame('09:00', $slots->first()['slot_start']->format('H:i'));
        $this->assertSame(1, $slots->first()['available_coach_count']);
    }

    /**
     * @group external-api
     * @group google-calendar
     */
    public function test_excludes_slot_when_all_coaches_are_busy_on_google_calendar(): void
    {
        $certification = Certification::factory()->published()->create();
        $coachA = User::factory()->coach()->create();
        $coachB = User::factory()->coach()->create();

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

        $googleCalendar = Mockery::mock(GoogleCalendarService::class);

        $googleCalendar->shouldReceive('isBusy')
            ->once()
            ->withArgs(fn (User $user, CarbonInterface $start, CarbonInterface $end): bool => $user->is($coachA)
                && $start->format('H:i') === '09:00'
                && $end->format('H:i') === '10:00'
            )
            ->andReturnTrue();

        $googleCalendar->shouldReceive('isBusy')
            ->once()
            ->withArgs(fn (User $user, CarbonInterface $start, CarbonInterface $end): bool => $user->is($coachB)
                && $start->format('H:i') === '09:00'
                && $end->format('H:i') === '10:00'
            )
            ->andReturnTrue();

        $this->app->instance(GoogleCalendarService::class, $googleCalendar);

        $slots = app(MeetingAvailabilityService::class)
            ->slotsForCertification($certification, Carbon::parse('2026-06-01'));

        $this->assertCount(0, $slots);
    }

    /**
     * @group external-api
     * @group google-calendar
     */
    public function test_skips_google_calendar_lookup_for_existing_reserved_meeting(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $this->attachCoach($certification, $coach);

        CoachAvailability::factory()
            ->forCoach($coach)
            ->onDay(1)
            ->timeRange('09:00:00', '10:00:00')
            ->create();

        Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => Carbon::parse('2026-06-01 09:00:00'),
            ]);

        $googleCalendar = Mockery::mock(GoogleCalendarService::class);

        $googleCalendar->shouldNotReceive('isBusy');

        $this->app->instance(GoogleCalendarService::class, $googleCalendar);

        $slots = app(MeetingAvailabilityService::class)
            ->slotsForCertification($certification, Carbon::parse('2026-06-01'));

        $this->assertCount(0, $slots);
    }

    /**
     * @group external-api
     * @group google-calendar
     */
    public function test_validate_slot_throws_when_google_calendar_is_busy(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();

        $this->attachCoach($certification, $coach);

        CoachAvailability::factory()
            ->forCoach($coach)
            ->onDay(1)
            ->timeRange('09:00:00', '10:00:00')
            ->create();

        $googleCalendar = Mockery::mock(GoogleCalendarService::class);

        $googleCalendar->shouldReceive('isBusy')
            ->once()
            ->withArgs(fn (User $user, CarbonInterface $start, CarbonInterface $end): bool => $user->is($coach)
                && $start->toIso8601String() === '2026-06-01T09:00:00+09:00'
                && $end->toIso8601String() === '2026-06-01T10:00:00+09:00'
            )
            ->andReturnTrue();

        $this->app->instance(GoogleCalendarService::class, $googleCalendar);

        $this->expectException(MeetingOutOfAvailabilityException::class);

        app(MeetingAvailabilityService::class)
            ->validateSlot($certification, Carbon::parse('2026-06-01 09:00:00'));
    }
}
