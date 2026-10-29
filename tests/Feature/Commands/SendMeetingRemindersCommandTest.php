<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Meeting;
use App\Models\MeetingReminderDelivery;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class SendMeetingRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_eve_reminder_notifies_student_and_coach_but_not_admin(): void
    {
        Notification::fake();

        $this->travelTo(now()->startOfDay()->setTime(9, 0));

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->create();

        Meeting::factory()
            ->reserved()
            ->forStudent($student)
            ->forCoach($coach)
            ->create([
                'scheduled_at' => now()->addDay()->setTime(10, 0),
            ]);

        $this->artisan('notifications:send-meeting-reminders', [
            '--window' => 'eve',
        ])->assertExitCode(0);

        Notification::assertSentToTimes(
            $student,
            MeetingReminderNotification::class,
            2
        );

        Notification::assertSentToTimes(
            $coach,
            MeetingReminderNotification::class,
            2
        );

        Notification::assertNotSentTo(
            $admin,
            MeetingReminderNotification::class
        );

        $this->assertDatabaseCount('meeting_reminder_deliveries', 2);
    }

    public function test_canceled_and_completed_meetings_are_excluded(): void
    {
        Notification::fake();

        $this->travelTo(now()->startOfDay()->setTime(9, 0));

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        foreach (['canceled', 'completed'] as $status) {
            Meeting::factory()
                ->{$status}()
                ->forStudent($student)
                ->forCoach($coach)
                ->create([
                    'scheduled_at' => now()->addDay()->setTime(
                        $status === 'canceled' ? 10 : 11,
                        0
                    ),
                ]);
        }

        $this->artisan('notifications:send-meeting-reminders', [
            '--window' => 'eve',
        ])->assertExitCode(0);

        Notification::assertNothingSent();

        $this->assertDatabaseCount('meeting_reminder_deliveries', 0);
    }

    public function test_running_same_window_twice_does_not_send_duplicates(): void
    {
        Notification::fake();

        $this->travelTo(now()->startOfDay()->setTime(9, 0));

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        Meeting::factory()
            ->reserved()
            ->forStudent($student)
            ->forCoach($coach)
            ->create([
                'scheduled_at' => now()->addDay()->setTime(10, 0),
            ]);

        $this->artisan('notifications:send-meeting-reminders', [
            '--window' => 'eve',
        ])->assertExitCode(0);

        $this->artisan('notifications:send-meeting-reminders', [
            '--window' => 'eve',
        ])->assertExitCode(0);

        Notification::assertSentToTimes(
            $student,
            MeetingReminderNotification::class,
            2
        );

        Notification::assertSentToTimes(
            $coach,
            MeetingReminderNotification::class,
            2
        );

        $this->assertDatabaseCount('meeting_reminder_deliveries', 2);
    }

    public function test_one_hour_before_reminder_matches_minute_boundary(): void
    {
        Notification::fake();

        $this->travelTo(
            now()->startOfDay()->setTime(10, 0, 30)
        );

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        Meeting::factory()
            ->reserved()
            ->forStudent($student)
            ->forCoach($coach)
            ->create([
                'scheduled_at' => now()->startOfMinute()->addHour(),
            ]);

        $this->artisan('notifications:send-meeting-reminders', [
            '--window' => 'one_hour_before',
        ])->assertExitCode(0);

        Notification::assertSentToTimes(
            $student,
            MeetingReminderNotification::class,
            2
        );

        Notification::assertSentToTimes(
            $coach,
            MeetingReminderNotification::class,
            2
        );

        $this->assertDatabaseCount('meeting_reminder_deliveries', 2);
    }

    public function test_command_reports_failure_when_notification_delivery_fails(): void
    {
        $this->travelTo(now()->startOfDay()->setTime(9, 0));

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $meeting = Meeting::factory()
            ->reserved()
            ->forStudent($student)
            ->forCoach($coach)
            ->create([
                'scheduled_at' => now()->addDay()->setTime(10, 0),
            ]);

        Notification::shouldReceive('send')
            ->andThrow(new RuntimeException('通知送信エラー'));

        $this->artisan('notifications:send-meeting-reminders', [
            '--window' => 'eve',
        ])->assertExitCode(1);

        $this->assertDatabaseHas('meeting_reminder_deliveries', [
            'meeting_id' => $meeting->id,
            'user_id' => $student->id,
            'database_sent_at' => null,
            'mail_sent_at' => null,
        ]);
    }

    public function test_retry_sends_only_failed_channel_without_duplicate(): void
    {
        Notification::fake();

        $this->travelTo(now()->startOfDay()->setTime(9, 0));

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $meeting = Meeting::factory()
            ->reserved()
            ->forStudent($student)
            ->forCoach($coach)
            ->create([
                'scheduled_at' => now()->addDay()->setTime(10, 0),
            ]);

        // 受講生のアプリ内通知だけが成功した状態を再現する。
        MeetingReminderDelivery::query()->create([
            'meeting_id' => $meeting->id,
            'user_id' => $student->id,
            'window' => 'eve',
            'database_sent_at' => now(),
            'mail_sent_at' => null,
        ]);

        // 失敗したメール通知のみを再送する。
        $this->artisan('notifications:send-meeting-reminders', [
            '--window' => 'eve',
        ])->assertExitCode(0);

        // 受講生にはメール通知1回のみ。
        Notification::assertSentToTimes(
            $student,
            MeetingReminderNotification::class,
            1
        );

        Notification::assertSentTo(
            $student,
            MeetingReminderNotification::class,
            function ($notification, $channels): bool {
                return $channels === ['mail'];
            }
        );

        // コーチにはアプリ内通知とメール通知の両方。
        Notification::assertSentToTimes(
            $coach,
            MeetingReminderNotification::class,
            2
        );

        $this->assertDatabaseHas('meeting_reminder_deliveries', [
            'meeting_id' => $meeting->id,
            'user_id' => $student->id,
            'window' => 'eve',
        ]);

        $this->assertNotNull(
            MeetingReminderDelivery::query()
                ->where('meeting_id', $meeting->id)
                ->where('user_id', $student->id)
                ->where('window', 'eve')
                ->firstOrFail()
                ->mail_sent_at
        );

        // 再実行しても通知が増えない。
        $this->artisan('notifications:send-meeting-reminders', [
            '--window' => 'eve',
        ])->assertExitCode(0);

        Notification::assertSentToTimes(
            $student,
            MeetingReminderNotification::class,
            1
        );

        Notification::assertSentToTimes(
            $coach,
            MeetingReminderNotification::class,
            2
        );

        $this->assertDatabaseCount('meeting_reminder_deliveries', 2);
    }
}
