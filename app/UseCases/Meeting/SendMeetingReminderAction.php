<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderDelivery;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class SendMeetingReminderAction
{
    /**
     * 指定された面談のリマインダーを送信する。
     *
     * @return int 送信が完了した通知チャンネルの数
     */
    public function __invoke(Meeting $meeting, string $window): int
    {
        if (! in_array($window, ['eve', 'one_hour_before'], true)) {
            throw new \InvalidArgumentException('不正な通知タイミングです。');
        }

        $sentCount = 0;
        $failed = false;

        foreach ([
            [$meeting->student_id, UserRole::Student],
            [$meeting->coach_id, UserRole::Coach],
        ] as [$userId, $role]) {
            try {
                $sentCount += $this->sendToUser(
                    meetingId: $meeting->id,
                    userId: $userId,
                    role: $role,
                    window: $window,
                );
            } catch (Throwable $exception) {
                report($exception);
                $failed = true;
            }
        }

        if ($failed) {
            throw new RuntimeException(
                '面談ID '.$meeting->id.' のリマインダー通知に失敗しました。'
            );
        }

        return $sentCount;
    }

    /**
     * 通知先ごとの配信処理。
     */
    private function sendToUser(
        string $meetingId,
        string $userId,
        UserRole $role,
        string $window,
    ): int {
        $failed = false;

        $sentCount = DB::transaction(function () use (
            $meetingId,
            $userId,
            $role,
            $window,
            &$failed,
        ): int {
            $meeting = Meeting::query()
                ->whereKey($meetingId)
                ->lockForUpdate()
                ->first();

            if ($meeting === null || $meeting->status !== MeetingStatus::Reserved) {
                return 0;
            }

            $user = User::query()
                ->whereKey($userId)
                ->where('role', $role->value)
                ->where('status', UserStatus::InProgress->value)
                ->first();

            if ($user === null) {
                return 0;
            }

            $delivery = MeetingReminderDelivery::query()
                ->firstOrCreate([
                    'meeting_id' => $meeting->id,
                    'user_id' => $user->id,
                    'window' => $window,
                ]);

            $delivery = MeetingReminderDelivery::query()
                ->whereKey($delivery->id)
                ->lockForUpdate()
                ->firstOrFail();

            $sentCount = 0;

            foreach ([
                'database' => 'database_sent_at',
                'mail' => 'mail_sent_at',
            ] as $channel => $sentAtColumn) {
                if ($delivery->{$sentAtColumn} !== null) {
                    continue;
                }

                try {
                    $user->notify(new MeetingReminderNotification(
                        meeting: $meeting,
                        window: $window,
                        channels: [$channel],
                    ));

                    $delivery->{$sentAtColumn} = now();
                    $delivery->save();

                    $sentCount++;
                } catch (Throwable $exception) {
                    report($exception);
                    $failed = true;
                }
            }

            return $sentCount;
        });

        // トランザクション確定後に失敗を通知する。
        if ($failed) {
            throw new RuntimeException(
                'ユーザーID '.$userId.' のリマインダー通知に失敗しました。'
            );
        }

        return $sentCount;
    }
}
