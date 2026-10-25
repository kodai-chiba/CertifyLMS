<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class MeetingNotificationDispatchTest extends TestCase
{
    use DatabaseMigrations;

    private function createMeeting(
        User $student,
        User $coach,
    ): Meeting {
        return Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDays(3)->startOfHour(),
            ]);
    }

    private function prepareReservation(): array
    {
        $student = User::factory()->student()->inProgress()->create([
            'max_meetings' => 3,
        ]);

        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);

        $admin = User::factory()->admin()->create();

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

        return [$student, $coach, $admin, $enrollment, $scheduledAt];
    }

    public function test_reservation_notifies_assigned_coach_only(): void
    {
        Notification::fake();

        [$student, $coach, $admin, $enrollment, $scheduledAt] = $this->prepareReservation();

        $otherCoach = User::factory()->coach()->inProgress()->create();

        $this->actingAs($student)
            ->post(route('meetings.store', $enrollment), [
                'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
                'topic' => '面談通知テスト',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('meetings', [
            'student_id' => $student->id,
            'coach_id' => $coach->id,
            'status' => MeetingStatus::Reserved->value,
        ]);

        Notification::assertSentTo(
            $coach,
            MeetingReservedNotification::class,
            function ($notification, $channels) {
                return in_array('database', $channels, true)
                    && in_array('mail', $channels, true);
            }
        );

        Notification::assertNotSentTo(
            $student,
            MeetingReservedNotification::class
        );

        Notification::assertNotSentTo(
            $otherCoach,
            MeetingReservedNotification::class
        );

        Notification::assertNotSentTo(
            $admin,
            MeetingReservedNotification::class
        );
    }

    public function test_student_cancellation_notifies_coach_only(): void
    {
        Notification::fake();

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->create();

        $meeting = $this->createMeeting($student, $coach);

        $this->actingAs($student)
            ->post(route('meetings.cancel', $meeting))
            ->assertRedirect();

        $this->assertSame(
            MeetingStatus::Canceled,
            $meeting->fresh()->status
        );

        Notification::assertSentTo(
            $coach,
            MeetingCanceledNotification::class
        );

        Notification::assertNotSentTo(
            $student,
            MeetingCanceledNotification::class
        );

        Notification::assertNotSentTo(
            $admin,
            MeetingCanceledNotification::class
        );
    }

    public function test_coach_cancellation_notifies_student_only(): void
    {
        Notification::fake();

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $meeting = $this->createMeeting($student, $coach);

        $this->actingAs($coach)
            ->post(route('meetings.cancel', $meeting))
            ->assertRedirect();

        Notification::assertSentTo(
            $student,
            MeetingCanceledNotification::class,
            function ($notification, $channels) {
                return in_array('database', $channels, true)
                    && in_array('mail', $channels, true);
            }
        );

        Notification::assertNotSentTo(
            $coach,
            MeetingCanceledNotification::class
        );
    }

    public function test_graduated_student_does_not_receive_cancellation_notification(): void
    {
        Notification::fake();

        $student = User::factory()->student()->graduated()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $meeting = $this->createMeeting($student, $coach);

        $this->actingAs($coach)
            ->post(route('meetings.cancel', $meeting))
            ->assertRedirect();

        Notification::assertNothingSent();
    }

    public function test_withdrawn_student_does_not_receive_cancellation_notification(): void
    {
        Notification::fake();

        $student = User::factory()->student()->withdrawn()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $meeting = $this->createMeeting($student, $coach);

        $this->actingAs($coach)
            ->post(route('meetings.cancel', $meeting))
            ->assertRedirect();

        Notification::assertNothingSent();
    }

    public function test_graduated_coach_does_not_receive_cancellation_notification(): void
    {
        Notification::fake();

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->graduated()->create();

        $meeting = $this->createMeeting($student, $coach);

        $this->actingAs($student)
            ->post(route('meetings.cancel', $meeting))
            ->assertRedirect();

        Notification::assertNothingSent();
    }

    public function test_withdrawn_coach_does_not_receive_cancellation_notification(): void
    {
        Notification::fake();

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->withdrawn()->create();

        $meeting = $this->createMeeting($student, $coach);

        $this->actingAs($student)
            ->post(route('meetings.cancel', $meeting))
            ->assertRedirect();

        Notification::assertNothingSent();
    }

    public function test_reservation_succeeds_even_if_notification_fails(): void
    {
        [$student, $coach, $admin, $enrollment, $scheduledAt] = $this->prepareReservation();

        // 通知送信時の例外を再現する。
        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('通知送信エラー'));

        $response = $this->actingAs($student)
            ->post(route('meetings.store', $enrollment), [
                'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
                'topic' => '通知失敗テスト',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('meetings', [
            'student_id' => $student->id,
            'coach_id' => $coach->id,
            'status' => MeetingStatus::Reserved->value,
        ]);
    }

    public function test_cancellation_succeeds_even_if_notification_fails(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $meeting = $this->createMeeting($student, $coach);

        // 通知送信時の例外を再現する。
        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('通知送信エラー'));

        $response = $this->actingAs($student)
            ->post(route('meetings.cancel', $meeting));

        $response->assertRedirect();

        $this->assertSame(
            MeetingStatus::Canceled,
            $meeting->fresh()->status
        );

        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
            'amount' => 1,
        ]);
    }
}
