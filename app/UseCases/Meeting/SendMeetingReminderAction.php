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

        return DB::transaction(function () use ($meeting, $window): int {
            $lockedMeeting = Meeting::query()
                ->whereKey($meeting->id)
                ->lockForUpdate()
                ->first();

            if ($lockedMeeting === null || $lockedMeeting->status !== MeetingStatus::Reserved) {
                return 0;
            }

            $sentCount = 0;

            foreach ([
                [$lockedMeeting->student_id, UserRole::Student],
                [$lockedMeeting->coach_id, UserRole::Coach],
            ] as [$userId, $role]) {
                $sentCount += $this->sendToUser(
                    meeting: $lockedMeeting,
                    userId: $userId,
                    role: $role,
                    window: $window,
                );
            }

            return $sentCount;
        });
    }

    /**
     * 通知先ごとの配信処理。
     *
     * 呼び出し元のトランザクション内で実行する。
     */
    private function sendToUser(
        Meeting $meeting,
        string $userId,
        UserRole $role,
        string $window,
    ): int {
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

            // 通知失敗時は例外を伝播させ、面談単位でロールバックする。
            $user->notify(new MeetingReminderNotification(
                meeting: $meeting,
                window: $window,
                channels: [$channel],
            ));

            $delivery->{$sentAtColumn} = now();
            $delivery->save();

            $sentCount++;
        }

        return $sentCount;
    }
}
