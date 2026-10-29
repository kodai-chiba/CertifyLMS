<?php

declare(strict_types=1);

namespace App\Console\Commands\Mentoring;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\UseCases\Meeting\SendMeetingReminderAction;
use Illuminate\Console\Command;
use Throwable;

class SendMeetingRemindersCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'notifications:send-meeting-reminders
                            {--window= : eve または one_hour_before}';

    /**
     * @var string
     */
    protected $description = '予約済み面談の前日・1時間前リマインダーを送信する';

    /**
     * リマインダー送信処理。
     */
    public function handle(SendMeetingReminderAction $action): int
    {
        $window = $this->option('window');

        if (! in_array($window, ['eve', 'one_hour_before'], true)) {
            $this->error('オプション --window には eve または one_hour_before を指定してください。');

            return self::FAILURE;
        }

        $now = now();

        if ($window === 'eve') {
            // 暫定仕様：翌日に開始する面談を対象とする
            $start = $now->copy()->addDay()->startOfDay();
            $end = $start->copy()->addDay();
        } else {
            // 現在時刻を分単位にそろえ、60分後の1分間を対象とする
            $start = $now->copy()->startOfMinute()->addHour();
            $end = $start->copy()->addMinute();
        }

        $meetingCount = 0;
        $sentCount = 0;
        $failedCount = 0;

        Meeting::query()
            ->where('status', MeetingStatus::Reserved->value)
            ->where('scheduled_at', '>=', $start)
            ->where('scheduled_at', '<', $end)
            ->orderBy('id')
            ->chunk(100, function ($meetings) use (
                $action,
                $window,
                &$meetingCount,
                &$sentCount,
                &$failedCount,
            ): void {
                foreach ($meetings as $meeting) {
                    $meetingCount++;

                    try {
                        $sentCount += $action($meeting, $window);
                    } catch (Throwable $exception) {
                        report($exception);
                        $failedCount++;

                        $this->error(
                            '面談ID '.$meeting->id.' の通知処理に失敗しました。'
                        );
                    }
                }
            });

        $this->info('対象面談数：'.$meetingCount);
        $this->info('送信成功チャンネル数：'.$sentCount);
        $this->info('処理失敗面談数：'.$failedCount);

        return $failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
