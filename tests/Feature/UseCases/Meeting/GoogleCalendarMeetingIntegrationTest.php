<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\MeetingQuotaService;
use App\UseCases\Meeting\CancelAction;
use App\UseCases\Meeting\StoreAction;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * @group external-api
 * @group google-calendar
 */
class GoogleCalendarMeetingIntegrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_creates_google_calendar_event_after_meeting_reservation_is_committed(): void
    {
        $data = $this->createReservationData();

        $googleCalendar = Mockery::mock(GoogleCalendarService::class);

        $googleCalendar->shouldReceive('isBusy')
            ->andReturnFalse();

        $calledMeetingId = null;
        $transactionLevel = null;

        $googleCalendar->shouldReceive('createMeetingEvent')
            ->once()
            ->withArgs(fn (Meeting $meeting): bool => $meeting->student_id === $data['student']->id
                && $meeting->coach_id === $data['coach']->id
                && $meeting->enrollment_id === $data['enrollment']->id
                && $meeting->status === MeetingStatus::Reserved
            )
            ->andReturnUsing(function (Meeting $meeting) use (&$calledMeetingId, &$transactionLevel): void {
                $calledMeetingId = $meeting->id;
                $transactionLevel = DB::transactionLevel();
            });

        $this->app->instance(GoogleCalendarService::class, $googleCalendar);

        $meeting = app(StoreAction::class)(
            $data['enrollment'],
            $data['scheduled_at'],
            'Google Calendarイベント作成確認',
        );

        $this->assertSame($meeting->id, $calledMeetingId);
        $this->assertSame(0, $transactionLevel);
        $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);

        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'student_id' => $data['student']->id,
            'coach_id' => $data['coach']->id,
            'status' => MeetingStatus::Reserved->value,
        ]);

        $this->assertSame(
            2,
            app(MeetingQuotaService::class)->remaining($data['student']),
        );

        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $data['student']->id,
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Consumed->value,
            'amount' => -1,
        ]);
    }

    public function test_preserves_meeting_reservation_when_google_calendar_event_creation_fails(): void
    {
        $data = $this->createReservationData();

        $googleCalendar = Mockery::mock(GoogleCalendarService::class);

        $googleCalendar->shouldReceive('isBusy')
            ->andReturnFalse();

        $googleCalendar->shouldReceive('createMeetingEvent')
            ->once()
            ->withArgs(fn (Meeting $meeting): bool => $meeting->student_id === $data['student']->id
                && $meeting->coach_id === $data['coach']->id
                && $meeting->enrollment_id === $data['enrollment']->id
            )
            ->andThrow(new RuntimeException('Google Calendar event creation failed.'));

        $this->app->instance(GoogleCalendarService::class, $googleCalendar);

        $meeting = app(StoreAction::class)(
            $data['enrollment'],
            $data['scheduled_at'],
            'イベント作成失敗時の面談予約確認',
        );

        $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
        $this->assertNull($meeting->fresh()->google_event_id);

        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'student_id' => $data['student']->id,
            'coach_id' => $data['coach']->id,
            'status' => MeetingStatus::Reserved->value,
        ]);

        $this->assertSame(
            2,
            app(MeetingQuotaService::class)->remaining($data['student']),
        );

        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $data['student']->id,
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Consumed->value,
            'amount' => -1,
        ]);
    }

    public function test_does_not_create_google_calendar_event_when_meeting_reservation_fails(): void
    {
        $data = $this->createReservationData(0);

        $googleCalendar = Mockery::mock(GoogleCalendarService::class);

        $googleCalendar->shouldNotReceive('isBusy');
        $googleCalendar->shouldNotReceive('createMeetingEvent');

        $this->app->instance(GoogleCalendarService::class, $googleCalendar);

        try {
            app(StoreAction::class)(
                $data['enrollment'],
                $data['scheduled_at'],
                '面談回数不足による予約失敗確認',
            );

            $this->fail('面談回数不足の例外が発生しませんでした。');
        } catch (InsufficientMeetingQuotaException $e) {
            $this->assertDatabaseCount('meetings', 0);
            $this->assertDatabaseCount('meeting_quota_transactions', 0);

            $this->assertSame(
                0,
                app(MeetingQuotaService::class)->remaining($data['student']),
            );
        }
    }

    public function test_deletes_google_calendar_event_after_meeting_cancellation_is_committed(): void
    {
        $data = $this->createCancellationData();

        $meeting = $data['meeting'];
        $student = $data['student'];

        $googleCalendar = Mockery::mock(GoogleCalendarService::class);

        $calledMeetingId = null;
        $transactionLevel = null;

        $googleCalendar->shouldReceive('deleteMeetingEvent')
            ->once()
            ->withArgs(fn (Meeting $target): bool => $target->is($meeting)
                && $target->status === MeetingStatus::Canceled
                && $target->google_event_id === 'lmstestevent123'
            )
            ->andReturnUsing(function (Meeting $target) use (&$calledMeetingId, &$transactionLevel): void {
                $calledMeetingId = $target->id;
                $transactionLevel = DB::transactionLevel();
            });

        $this->app->instance(GoogleCalendarService::class, $googleCalendar);

        app(CancelAction::class)($meeting, $student);

        $meeting->refresh();

        $this->assertSame($meeting->id, $calledMeetingId);
        $this->assertSame(0, $transactionLevel);
        $this->assertSame(MeetingStatus::Canceled, $meeting->status);
        $this->assertSame($student->id, $meeting->canceled_by_user_id);
        $this->assertNotNull($meeting->canceled_at);

        $this->assertSame(
            5,
            app(MeetingQuotaService::class)->remaining($student),
        );

        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
            'amount' => 1,
        ]);
    }

    public function test_preserves_meeting_cancellation_when_google_calendar_event_deletion_fails(): void
    {
        $data = $this->createCancellationData();

        $meeting = $data['meeting'];
        $student = $data['student'];

        $googleCalendar = Mockery::mock(GoogleCalendarService::class);

        $googleCalendar->shouldReceive('deleteMeetingEvent')
            ->once()
            ->withArgs(fn (Meeting $target): bool => $target->is($meeting)
                && $target->status === MeetingStatus::Canceled
                && $target->google_event_id === 'lmstestevent123'
            )
            ->andThrow(new RuntimeException('Google Calendar event deletion failed.'));

        $this->app->instance(GoogleCalendarService::class, $googleCalendar);

        app(CancelAction::class)($meeting, $student);

        $meeting->refresh();

        $this->assertSame(MeetingStatus::Canceled, $meeting->status);
        $this->assertSame($student->id, $meeting->canceled_by_user_id);
        $this->assertNotNull($meeting->canceled_at);
        $this->assertSame('lmstestevent123', $meeting->google_event_id);

        $this->assertSame(
            5,
            app(MeetingQuotaService::class)->remaining($student),
        );

        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
            'amount' => 1,
        ]);
    }

    public function test_does_not_delete_google_calendar_event_twice_when_meeting_is_already_canceled(): void
    {
        $data = $this->createCancellationData();

        $meeting = $data['meeting'];
        $student = $data['student'];

        $googleCalendar = Mockery::mock(GoogleCalendarService::class);

        $googleCalendar->shouldReceive('deleteMeetingEvent')
            ->once()
            ->withArgs(fn (Meeting $target): bool => $target->is($meeting)
                && $target->status === MeetingStatus::Canceled
            )
            ->andReturnNull();

        $this->app->instance(GoogleCalendarService::class, $googleCalendar);

        $cancelAction = app(CancelAction::class);

        $cancelAction($meeting, $student);

        try {
            $cancelAction($meeting->fresh(), $student);

            $this->fail('キャンセル済み面談の再キャンセルが拒否されませんでした。');
        } catch (MeetingStatusTransitionException $e) {
            $this->assertSame(
                MeetingStatus::Canceled,
                $meeting->fresh()->status,
            );

            $this->assertSame(
                5,
                app(MeetingQuotaService::class)->remaining($student),
            );

            $this->assertSame(
                1,
                DB::table('meeting_quota_transactions')
                    ->where('user_id', $student->id)
                    ->where('related_meeting_id', $meeting->id)
                    ->where('type', MeetingQuotaTransactionType::Refunded->value)
                    ->count(),
            );
        }
    }

    private function createReservationData(int $maxMeetings = 3): array
    {
        $student = User::factory()->student()->inProgress()->create([
            'max_meetings' => $maxMeetings,
        ]);

        $admin = User::factory()->admin()->create();

        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);

        $certification = Certification::factory()->published()->create();

        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);

        CoachAvailability::factory()
            ->forCoach($coach)
            ->onDay(1)
            ->timeRange('09:00:00', '18:00:00')
            ->create();

        $enrollment = Enrollment::factory()
            ->for($student, 'user')
            ->for($certification)
            ->learning()
            ->create();

        $scheduledAt = now()
            ->startOfDay()
            ->next(Carbon::MONDAY)
            ->setTime(10, 0);

        return [
            'student' => $student,
            'coach' => $coach,
            'enrollment' => $enrollment,
            'scheduled_at' => $scheduledAt,
        ];
    }

    private function createCancellationData(): array
    {
        $student = User::factory()->student()->inProgress()->create([
            'max_meetings' => 5,
        ]);

        $coach = User::factory()->coach()->inProgress()->create();

        $meeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDays(3)->startOfHour(),
                'google_event_id' => 'lmstestevent123',
            ]);

        app(ConsumeQuotaAction::class)($student, $meeting->id);

        $this->assertSame(
            4,
            app(MeetingQuotaService::class)->remaining($student),
        );

        return [
            'student' => $student,
            'coach' => $coach,
            'meeting' => $meeting,
        ];
    }
}
