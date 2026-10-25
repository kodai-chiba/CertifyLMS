<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingReservedNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private readonly Meeting $meeting,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('【Certify LMS】面談予約が入りました')
            ->greeting('新しい面談予約が入りました')
            ->line('担当する受講生から面談の予約が入りました。')
            ->line('面談日時：'.$this->meeting->scheduled_at->format('Y年m月d日 H:i'))
            ->action('面談詳細を確認する', route('meetings.show', $this->meeting))
            ->line('Certify LMSをご利用いただきありがとうございます。');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'notification_type' => 'meeting_reserved',
            'title' => '新しい面談予約が入りました',
            'message' => '担当する受講生から面談の予約が入りました。',
            'body' => '面談日時：'.$this->meeting->scheduled_at->format('Y年m月d日 H:i').'。面談詳細画面から内容をご確認ください。',
            'url' => route('meetings.show', $this->meeting, false),
            'meeting_id' => $this->meeting->id,
        ];
    }
}
