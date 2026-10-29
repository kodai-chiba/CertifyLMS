<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

class MeetingReminderNotification extends Notification
{
    use Queueable;

    /**
     * @param array<int, string> $channels
     */
    public function __construct(
        private readonly Meeting $meeting,
        private readonly string $window,
        private readonly array $channels = ['database', 'mail'],
    ) {
        if (! in_array($window, ['eve', 'one_hour_before'], true)) {
            throw new InvalidArgumentException('不正なリマインダー通知タイミングです。');
        }

        if ($channels === [] || array_diff($channels, ['database', 'mail']) !== []) {
            throw new InvalidArgumentException('不正な通知チャンネルです。');
        }
    }

    /**
     * 通知の配信方法。
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /**
     * メール通知の内容。
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('【Certify LMS】'.$this->title())
            ->greeting($notifiable->name.'様')
            ->line($this->message())
            ->line('面談日時：'.$this->formattedScheduledAt())
            ->action(
                '面談詳細を確認する',
                route('meetings.show', $this->meeting),
            )
            ->line('Certify LMSをご利用いただきありがとうございます。');
    }

    /**
     * アプリ内通知の内容。
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'notification_type' => 'meeting_reminder',
            'title' => $this->title(),
            'message' => $this->message(),
            'body' => '面談日時：'.$this->formattedScheduledAt().'。面談詳細画面から内容をご確認ください。',
            'url' => route('meetings.show', $this->meeting, false),
            'meeting_id' => $this->meeting->id,
            'window' => $this->window,
        ];
    }

    /**
     * 通知タイトル。
     */
    private function title(): string
    {
        return match ($this->window) {
            'eve' => '明日は面談の予定があります',
            'one_hour_before' => '面談開始まであと1時間です',
        };
    }

    /**
     * 通知メッセージ。
     */
    private function message(): string
    {
        return match ($this->window) {
            'eve' => '明日は予約済みの面談があります。開始日時をご確認ください。',
            'one_hour_before' => '予約済みの面談が約1時間後に始まります。準備をお願いいたします。',
        };
    }

    /**
     * 面談開始日時の表示形式。
     */
    private function formattedScheduledAt(): string
    {
        return $this->meeting->scheduled_at->format('Y年m月d日 H:i');
    }
}
