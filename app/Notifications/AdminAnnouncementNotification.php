<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminAnnouncementNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Announcement $announcement
    ) {}

    /**
     * 通知の配信方法
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * メール通知
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('【Certify LMS】'.$this->announcement->title)
            ->greeting($notifiable->name.' 様')
            ->line('運営からのお知らせが届きました。')
            ->line('【'.$this->announcement->title.'】')
            ->line($this->announcement->body)
            ->action(
                'お知らせを確認する',
                route('notifications.index')
            );
    }

    /**
     * アプリ内通知
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'notification_type' => 'admin_announcement',
            'announcement_id' => $this->announcement->id,
            'title' => $this->announcement->title,
            'message' => $this->announcement->body,
            'body' => $this->announcement->body,
            'url' => route('notifications.show', [
                'notification' => $this->id,
            ], false),
        ];
    }
}
