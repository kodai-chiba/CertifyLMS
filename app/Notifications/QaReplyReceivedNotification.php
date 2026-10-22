<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\QaReply;
use App\Models\QaThread;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QaReplyReceivedNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private readonly QaThread $thread,
        private readonly QaReply $reply,
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
            ->subject('【Certify LMS】質問に返信が届きました')
            ->greeting('質問に返信が届きました')
            ->line('投稿した質問に新しい返信がありました。')
            ->action('質問を確認する', route('qa-board.show', $this->thread))
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
            'notification_type' => 'qa_reply_received',
            'title' => '質問に返信が届きました',
            'message' => '投稿した質問に新しい返信がありました。',
            'body' => '投稿した質問に新しい返信がありました。質問詳細画面から内容をご確認ください。',
            'url' => route('qa-board.show', $this->thread, false),
            'thread_id' => $this->thread->id,
            'reply_id' => $this->reply->id,
        ];
    }
}
