<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingOutOfAvailabilityException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Services\MeetingQuotaService;
use App\UseCases\Meeting\StoreAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreActionTest extends TestCase
{
    use RefreshDatabase;

    private function attachCoach(
        Certification $certification,
        User $coach,
        User $admin,
    ): void {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
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

        $this->attachCoach($certification, $coach, $admin);

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

    public function test_creates_reserved_meeting(): void
    {
        $data = $this->createReservationData();

        $meeting = app(StoreAction::class)(
            $data['enrollment'],
            $data['scheduled_at'],
            '相談したい',
        );

        $this->assertInstanceOf(Meeting::class, $meeting);
        $this->assertSame(MeetingStatus::Reserved, $meeting->status);

        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'enrollment_id' => $data['enrollment']->id,
            'student_id' => $data['student']->id,
            'coach_id' => $data['coach']->id,
            'topic' => '相談したい',
        ]);
    }

    public function test_consumes_one_meeting_quota(): void
    {
        $data = $this->createReservationData();

        $quotaService = app(MeetingQuotaService::class);

        $this->assertSame(3, $quotaService->remaining($data['student']));

        $meeting = app(StoreAction::class)(
            $data['enrollment'],
            $data['scheduled_at'],
            '相談したい',
        );

        $this->assertSame(2, $quotaService->remaining($data['student']));

        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $data['student']->id,
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Consumed->value,
            'amount' => -1,
        ]);

        $transaction = MeetingQuotaTransaction::query()
            ->where('related_meeting_id', $meeting->id)
            ->firstOrFail();

        $this->assertSame(
            $transaction->id,
            $meeting->meeting_quota_transaction_id,
        );
    }

    public function test_rejects_reservation_when_quota_is_insufficient(): void
    {
        $data = $this->createReservationData(0);

        try {
            app(StoreAction::class)(
                $data['enrollment'],
                $data['scheduled_at'],
                '相談したい',
            );

            $this->fail('面談回数不足の例外が発生しませんでした。');
        } catch (InsufficientMeetingQuotaException $e) {
            $this->assertDatabaseCount('meetings', 0);
            $this->assertDatabaseCount('meeting_quota_transactions', 0);
        }
    }

    public function test_rejects_reservation_when_coach_slot_is_occupied(): void
    {
        $data = $this->createReservationData();

        $otherStudent = User::factory()->student()->inProgress()->create();

        Meeting::factory()
            ->reserved()
            ->forCoach($data['coach'])
            ->forStudent($otherStudent)
            ->create([
                'scheduled_at' => $data['scheduled_at'],
            ]);

        try {
            app(StoreAction::class)(
                $data['enrollment'],
                $data['scheduled_at'],
                '相談したい',
            );

            $this->fail('予約枠が埋まっている場合の例外が発生しませんでした。');
        } catch (MeetingOutOfAvailabilityException $e) {
            $this->assertDatabaseCount('meetings', 1);

            $this->assertDatabaseMissing('meetings', [
                'enrollment_id' => $data['enrollment']->id,
            ]);

            $this->assertSame(
                3,
                app(MeetingQuotaService::class)->remaining($data['student']),
            );

            $this->assertDatabaseCount('meeting_quota_transactions', 0);
        }
    }

    public function test_saves_assigned_coach_and_meeting_url(): void
    {
        $data = $this->createReservationData();

        $meeting = app(StoreAction::class)(
            $data['enrollment'],
            $data['scheduled_at'],
            '面談URL確認',
        );

        $this->assertSame($data['coach']->id, $meeting->coach_id);

        $this->assertSame(
            'https://meet.example.com/coach-room',
            $meeting->meeting_url_snapshot,
        );

        $this->assertSame('面談URL確認', $meeting->topic);
    }
}
