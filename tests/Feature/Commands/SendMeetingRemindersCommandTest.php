<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Meeting;
use App\Models\MeetingReminderDelivery;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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
                'created_at' => now()->subDays(2),
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
                    'created_at' => now()->subDays(2),
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
                'created_at' => now()->subDays(2),
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

    public function test_command_reports_failure_and_rolls_back_delivery_records(): void
    {
        $this->travelTo(now()->startOfDay()->setTime(9, 0));

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        Meeting::factory()
            ->reserved()
            ->forStudent($student)
            ->forCoach($coach)
            ->create([
                'scheduled_at' => now()->addDay()->setTime(10, 0),
                'created_at' => now()->subDays(2),
            ]);

        Notification::shouldReceive('send')
            ->andThrow(new RuntimeException('通知送信エラー'));

        $this->artisan('notifications:send-meeting-reminders', [
            '--window' => 'eve',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('meeting_reminder_deliveries', 0);
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
                'created_at' => now()->subDays(2),
            ]);

        // 既存の部分配信履歴がある場合も、未送信チャンネルだけを送信する。
        MeetingReminderDelivery::query()->create([
            'meeting_id' => $meeting->id,
            'user_id' => $student->id,
            'window' => 'eve',
            'database_sent_at' => now(),
            'mail_sent_at' => null,
        ]);

        $this->artisan('notifications:send-meeting-reminders', [
            '--window' => 'eve',
        ])->assertExitCode(0);

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

        Notification::assertSentToTimes(
            $coach,
            MeetingReminderNotification::class,
            2
        );

        $this->assertDatabaseCount('meeting_reminder_deliveries', 2);
    }

    public function test_mail_failure_rolls_back_database_notification_and_delivery_records(): void
    {
        $this->travelTo(now()->startOfDay()->setTime(9, 0));

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        Meeting::factory()
            ->reserved()
            ->forStudent($student)
            ->forCoach($coach)
            ->create([
                'scheduled_at' => now()->addDay()->setTime(10, 0),
                'created_at' => now()->subDays(2),
            ]);

        // 実際のdatabaseチャンネルを使用し、mailチャンネルのみ失敗させる。
        Notification::shouldReceive('send')
            ->andReturnUsing(function ($notifiable, $notification): void {
                $channels = $notification->via($notifiable);

                if ($channels === ['database']) {
                    $notifiable->notifications()->create([
                        'id' => (string) Str::uuid(),
                        'type' => get_class($notification),
                        'data' => $notification->toArray($notifiable),
                    ]);

                    return;
                }

                if ($channels === ['mail']) {
                    throw new RuntimeException('メール送信エラー');
                }
            });

        $this->artisan('notifications:send-meeting-reminders', [
            '--window' => 'eve',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('meeting_reminder_deliveries', 0);
    }

    public function test_coach_notification_failure_rolls_back_student_delivery_records(): void
    {
        $this->travelTo(now()->startOfDay()->setTime(9, 0));

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        Meeting::factory()
            ->reserved()
            ->forStudent($student)
            ->forCoach($coach)
            ->create([
                'scheduled_at' => now()->addDay()->setTime(10, 0),
                'created_at' => now()->subDays(2),
            ]);

        Notification::shouldReceive('send')
            ->andReturnUsing(function ($notifiable, $notification) use ($coach): void {
                if ($notifiable->id === $coach->id) {
                    throw new RuntimeException('コーチへの通知エラー');
                }
            });

        $this->artisan('notifications:send-meeting-reminders', [
            '--window' => 'eve',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('meeting_reminder_deliveries', 0);
    }

    public function test_eve_reminder_skips_meeting_booked_on_previous_day(): void
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
                'created_at' => now(),
            ]);

        $this->artisan('notifications:send-meeting-reminders', [
            '--window' => 'eve',
        ])->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('meeting_reminder_deliveries', 0);
    }

    public function test_one_hour_reminder_sends_for_same_day_booking(): void
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
                'scheduled_at' => now()->addHour(),
                'created_at' => now(),
            ]);

        $this->artisan('notifications:send-meeting-reminders', [
            '--window' => 'one_hour_before',
        ])->assertExitCode(0);

        Notification::assertSentToTimes($student, MeetingReminderNotification::class, 2);
        Notification::assertSentToTimes($coach, MeetingReminderNotification::class, 2);
        $this->assertDatabaseCount('meeting_reminder_deliveries', 2);
    }
}
