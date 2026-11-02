<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\EnrollmentStatus;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingReservationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

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
}
