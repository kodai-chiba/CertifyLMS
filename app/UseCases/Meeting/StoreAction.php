<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReservedNotification;
use App\Services\CoachMeetingLoadService;
use App\Services\GoogleCalendarService;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class StoreAction
{
    public function __construct(
        private readonly MeetingAvailabilityService $availabilityService,
        private readonly CoachMeetingLoadService $coachLoadService,
        private readonly GoogleCalendarService $googleCalendarService,
        private readonly MeetingQuotaService $quotaService,
        private readonly ConsumeQuotaAction $consumeAction,
    ) {}

    public function __invoke(
        Enrollment $enrollment,
        Carbon $scheduledAt,
        ?string $topic,
    ): Meeting {
        $student = $enrollment->user;

        return DB::transaction(function () use (
            $enrollment,
            $student,
            $scheduledAt,
            $topic,
        ): Meeting {
            if ($this->quotaService->remaining($student) < 1) {
                throw new InsufficientMeetingQuotaException;
            }

            $this->availabilityService->validateSlot(
                $enrollment->certification,
                $scheduledAt,
            );

            $candidates = $this->findAvailableCoaches(
                $enrollment->certification,
                $scheduledAt,
            );

            if ($candidates->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }

            $coach = $this->coachLoadService->leastLoadedCoach($candidates);

            try {
                $meeting = Meeting::create([
                    'enrollment_id' => $enrollment->id,
                    'coach_id' => $coach->id,
                    'student_id' => $student->id,
                    'scheduled_at' => $scheduledAt,
                    'status' => MeetingStatus::Reserved->value,
                    'topic' => $topic,
                    'meeting_url_snapshot' => $coach->meeting_url,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // 同時刻の予約競合は既存の例外へ変換する。
                throw new MeetingNoAvailableCoachException($e);
            }

            $transaction = ($this->consumeAction)($student, $meeting->id);

            $meeting->update([
                'meeting_quota_transaction_id' => $transaction->id,
            ]);

            // 予約確定後、担当コーチにのみ通知する。
            DB::afterCommit(function () use ($meeting, $student): void {
                try {
                    if ($student->role !== UserRole::Student) {
                        return;
                    }

                    $coach = User::query()
                        ->whereKey($meeting->coach_id)
                        ->where('role', UserRole::Coach->value)
                        ->where('status', UserStatus::InProgress->value)
                        ->first();

                    if ($coach === null) {
                        return;
                    }

                    $coach->notify(new MeetingReservedNotification($meeting));
                } catch (Throwable $e) {
                    report($e);
                }
            });

            // LMSの予約確定後にGoogleカレンダーへ登録する。
            // 外部APIの失敗は予約成立に影響させない。
            DB::afterCommit(function () use ($meeting): void {
                try {
                    $this->googleCalendarService->createMeetingEvent($meeting);
                } catch (Throwable $e) {
                    report($e);
                }
            });

            return $meeting->fresh();
        });
    }

    /**
     * @return Collection<int, User>
     */
    private function findAvailableCoaches(
        Certification $certification,
        Carbon $scheduledAt,
    ): Collection {
        $time = $scheduledAt->format('H:i:s');

        return $certification->coaches()
            ->whereHas('coachAvailabilities', function ($q) use ($scheduledAt, $time) {
                $q->where('day_of_week', $scheduledAt->dayOfWeek)
                    ->where('is_active', true)
                    ->where('start_time', '<=', $time)
                    ->where('end_time', '>', $time);
            })
            ->whereDoesntHave('meetingsAsCoach', function ($q) use ($scheduledAt) {
                $q->where('scheduled_at', $scheduledAt)
                    ->whereIn('status', [
                        MeetingStatus::Reserved->value,
                        MeetingStatus::Completed->value,
                    ]);
            })
            ->get()
            ->filter(fn (User $coach): bool => ! $this->googleCalendarService->isBusy(
                $coach,
                $scheduledAt,
                $scheduledAt->copy()->addHour(),
            ))
            ->values();
    }
}
