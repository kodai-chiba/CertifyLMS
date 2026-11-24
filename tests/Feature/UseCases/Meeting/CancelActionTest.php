<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Services\MeetingQuotaService;
use App\UseCases\Meeting\CancelAction;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_cancel_reserved_meeting_and_receive_quota_refund(): void
    {
        $student = User::factory()->student()->inProgress()->create([
            'max_meetings' => 5,
        ]);
        $coach = User::factory()->coach()->create();

        $meeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDays(3)->startOfHour(),
            ]);

        $quotaService = app(MeetingQuotaService::class);

        app(ConsumeQuotaAction::class)($student, $meeting->id);

        $this->assertSame(4, $quotaService->remaining($student));

        app(CancelAction::class)($meeting, $student);

        $meeting->refresh();

        $this->assertSame(MeetingStatus::Canceled, $meeting->status);
        $this->assertSame($student->id, $meeting->canceled_by_user_id);
        $this->assertNotNull($meeting->canceled_at);
        $this->assertSame(5, $quotaService->remaining($student));

        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
            'amount' => 1,
        ]);
    }

    public function test_coach_can_cancel_reserved_meeting_and_refund_student_quota(): void
    {
        $student = User::factory()->student()->inProgress()->create([
            'max_meetings' => 5,
        ]);
        $coach = User::factory()->coach()->create();

        $meeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDays(3)->startOfHour(),
            ]);

        $quotaService = app(MeetingQuotaService::class);

        app(ConsumeQuotaAction::class)($student, $meeting->id);

        $this->assertSame(4, $quotaService->remaining($student));

        app(CancelAction::class)($meeting, $coach);

        $meeting->refresh();

        $this->assertSame(MeetingStatus::Canceled, $meeting->status);
        $this->assertSame($coach->id, $meeting->canceled_by_user_id);
        $this->assertNotNull($meeting->canceled_at);
        $this->assertSame(5, $quotaService->remaining($student));

        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
            'amount' => 1,
        ]);
    }

    public function test_canceled_meeting_cannot_be_canceled_again(): void
    {
        $student = User::factory()->student()->inProgress()->create([
            'max_meetings' => 5,
        ]);
        $coach = User::factory()->coach()->create();

        $meeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->addDays(3)->startOfHour(),
            ]);

        app(ConsumeQuotaAction::class)($student, $meeting->id);

        $cancelAction = app(CancelAction::class);

        $cancelAction($meeting, $student);

        try {
            $cancelAction($meeting, $student);

            $this->fail('キャンセル済み面談の再キャンセルが拒否されませんでした。');
        } catch (MeetingStatusTransitionException $e) {
            $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);

            $this->assertSame(
                1,
                MeetingQuotaTransaction::query()
                    ->where('user_id', $student->id)
                    ->where('related_meeting_id', $meeting->id)
                    ->where('type', MeetingQuotaTransactionType::Refunded)
                    ->count()
            );
        }
    }

    public function test_started_meeting_cannot_be_canceled(): void
    {
        $student = User::factory()->student()->inProgress()->create([
            'max_meetings' => 5,
        ]);
        $coach = User::factory()->coach()->create();

        $meeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create([
                'scheduled_at' => now()->subHour(),
            ]);

        $this->expectException(MeetingAlreadyStartedException::class);

        try {
            app(CancelAction::class)($meeting, $student);
        } finally {
            $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);

            $this->assertDatabaseMissing('meeting_quota_transactions', [
                'related_meeting_id' => $meeting->id,
                'type' => MeetingQuotaTransactionType::Refunded->value,
            ]);
        }
    }

    public function test_completed_meeting_cannot_be_canceled(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();

        $meeting = Meeting::factory()
            ->completed()
            ->forCoach($coach)
            ->forStudent($student)
            ->create();

        $this->expectException(MeetingStatusTransitionException::class);

        app(CancelAction::class)($meeting, $student);
    }
}
