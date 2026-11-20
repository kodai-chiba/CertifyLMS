<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\EnrollmentStatus;
use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingReservationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const PLAN_EXPIRED_MESSAGE = 'プラン期間が満了しました。プラン機能はご利用いただけません。プロフィール / 修了証は引き続きアクセス可能です。';

    public function test_only_in_progress_students_can_access_meeting_creation(): void
    {
        foreach ([
            UserStatus::Invited,
            UserStatus::Graduated,
            UserStatus::Withdrawn,
        ] as $status) {
            $student = User::factory()->student()->create([
                'status' => $status,
            ]);

            $certification = Certification::factory()->published()->create();

            $enrollment = Enrollment::factory()
                ->for($student, 'user')
                ->for($certification)
                ->create([
                    'status' => EnrollmentStatus::Learning,
                ]);

            $this->actingAs($student)
                ->get(route('meetings.create', $enrollment))
                ->assertForbidden();
        }
    }

    public function test_only_in_progress_students_can_store_meeting_reservations(): void
    {
        foreach ([
            UserStatus::Invited,
            UserStatus::Graduated,
            UserStatus::Withdrawn,
        ] as $status) {
            $student = User::factory()->student()->create([
                'status' => $status,
            ]);

            $certification = Certification::factory()->published()->create();

            $enrollment = Enrollment::factory()
                ->for($student, 'user')
                ->for($certification)
                ->create([
                    'status' => EnrollmentStatus::Learning,
                ]);

            $this->actingAs($student)
                ->post(route('meetings.store', $enrollment), [
                    'scheduled_at' => now()->addDays(3)->startOfHour()->format('Y-m-d\TH:i:s'),
                    'topic' => '面談予約の認可テスト',
                ])
                ->assertForbidden();

            $this->assertDatabaseMissing('meetings', [
                'enrollment_id' => $enrollment->id,
            ]);
        }

        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_non_in_progress_students_cannot_access_own_reserved_meetings(): void
    {
        foreach ([
            UserStatus::Invited,
            UserStatus::Graduated,
            UserStatus::Withdrawn,
        ] as $status) {
            $coach = User::factory()->coach()->create();

            $student = User::factory()->student()->create([
                'status' => $status,
            ]);

            $meeting = Meeting::factory()
                ->forCoach($coach)
                ->forStudent($student)
                ->reserved()
                ->create();

            $this->actingAs($student)
                ->get(route('meetings.show', $meeting))
                ->assertForbidden()
                ->assertSeeText(self::PLAN_EXPIRED_MESSAGE);
        }
    }

    public function test_non_in_progress_students_cannot_access_own_completed_meetings(): void
    {
        foreach ([
            UserStatus::Invited,
            UserStatus::Graduated,
            UserStatus::Withdrawn,
        ] as $status) {
            $coach = User::factory()->coach()->create();

            $student = User::factory()->student()->create([
                'status' => $status,
            ]);

            $meeting = Meeting::factory()
                ->forCoach($coach)
                ->forStudent($student)
                ->completed()
                ->create();

            $this->actingAs($student)
                ->get(route('meetings.show', $meeting))
                ->assertForbidden()
                ->assertSeeText(self::PLAN_EXPIRED_MESSAGE);
        }
    }

    public function test_non_in_progress_students_cannot_cancel_own_meetings(): void
    {
        foreach ([
            UserStatus::Invited,
            UserStatus::Graduated,
            UserStatus::Withdrawn,
        ] as $status) {
            $coach = User::factory()->coach()->create();

            $student = User::factory()->student()->create([
                'status' => $status,
            ]);

            $meeting = Meeting::factory()
                ->forCoach($coach)
                ->forStudent($student)
                ->reserved()
                ->create([
                    'scheduled_at' => now()->addDays(3)->startOfHour(),
                ]);

            $this->actingAs($student)
                ->post(route('meetings.cancel', $meeting))
                ->assertForbidden()
                ->assertSeeText(self::PLAN_EXPIRED_MESSAGE);

            $this->assertSame(
                MeetingStatus::Reserved,
                $meeting->fresh()->status
            );

            $this->assertDatabaseMissing('meeting_quota_transactions', [
                'user_id' => $student->id,
                'related_meeting_id' => $meeting->id,
                'type' => MeetingQuotaTransactionType::Refunded->value,
            ]);
        }
    }

    public function test_admin_can_access_graduated_students_meeting(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->graduated()->create();

        $meeting = Meeting::factory()
            ->forCoach($coach)
            ->forStudent($student)
            ->completed()
            ->create();

        $this->actingAs($admin)
            ->get(route('meetings.show', $meeting))
            ->assertOk();
    }

    public function test_assigned_coach_can_access_graduated_students_meeting(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->graduated()->create();

        $meeting = Meeting::factory()
            ->forCoach($coach)
            ->forStudent($student)
            ->completed()
            ->create();

        $this->actingAs($coach)
            ->get(route('meetings.show', $meeting))
            ->assertOk();
    }
}
