<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\Services\GoogleCalendarService;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 面談キャンセル処理。
 *
 * 面談の状態確認、キャンセル確定、面談回数の返却を
 * 同一トランザクション内で実行する。
 *
 * Googleカレンダーのイベント削除と相手への通知は、
 * トランザクション確定後に実行する。
 */
final class CancelAction
{
    public function __construct(
        private readonly RefundQuotaAction $refundAction,
        private readonly GoogleCalendarService $googleCalendarService,
    ) {}

    public function __invoke(Meeting $meeting, User $actor): void
    {
        DB::transaction(function () use ($meeting, $actor): void {
            $locked = Meeting::query()
                ->whereKey($meeting->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== MeetingStatus::Reserved) {
                throw MeetingStatusTransitionException::forCancel();
            }

            if ($locked->scheduled_at->lessThanOrEqualTo(now())) {
                throw new MeetingAlreadyStartedException;
            }

            $locked->update([
                'status' => MeetingStatus::Canceled->value,
                'canceled_by_user_id' => $actor->id,
                'canceled_at' => now(),
            ]);

            // キャンセルと面談回数の返却を同一トランザクション内で行う。
            $student = User::query()->findOrFail($locked->student_id);

            ($this->refundAction)($student, $locked->id);

            // LMSのキャンセル確定後にGoogleカレンダーのイベントを削除する。
            // 外部APIの失敗はLMSのキャンセルや面談回数返却に影響させない。
            DB::afterCommit(function () use ($locked): void {
                try {
                    $this->googleCalendarService->deleteMeetingEvent($locked);
                } catch (Throwable $e) {
                    report($e);
                }
            });

            // トランザクション確定後、キャンセル実行者の相手にのみ通知する。
            DB::afterCommit(function () use ($locked, $actor): void {
                try {
                    $recipientId = match ($actor->role) {
                        UserRole::Student => $locked->coach_id,
                        UserRole::Coach => $locked->student_id,
                        default => null,
                    };

                    $recipientRole = match ($actor->role) {
                        UserRole::Student => UserRole::Coach,
                        UserRole::Coach => UserRole::Student,
                        default => null,
                    };

                    if ($recipientId === null || $recipientRole === null) {
                        return;
                    }

                    // キャンセル実行者自身には通知しない。
                    if ($recipientId === $actor->id) {
                        return;
                    }

                    $recipient = User::query()
                        ->whereKey($recipientId)
                        ->where('role', $recipientRole->value)
                        ->where('status', UserStatus::InProgress->value)
                        ->first();

                    if ($recipient === null) {
                        return;
                    }

                    $recipient->notify(new MeetingCanceledNotification($locked));
                } catch (Throwable $e) {
                    report($e);
                }
            });
        });
    }
}
