<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QaReplyReceivedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_uses_database_and_mail_channels(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $thread = QaThread::factory()->create([
            'user_id' => $user->id,
        ]);
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
        ]);

        $notification = new QaReplyReceivedNotification($thread, $reply);

        $this->assertSame(
            ['database', 'mail'],
            $notification->via($user)
        );
    }

    public function test_notification_contains_correct_database_data(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $user->id,
        ]);

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
        ]);

        $notification = new QaReplyReceivedNotification($thread, $reply);

        $data = $notification->toArray($user);

        $this->assertSame(
            'qa_reply_received',
            $data['notification_type']
        );

        $this->assertSame(
            '質問に返信が届きました',
            $data['title']
        );

        $this->assertSame(
            $thread->id,
            $data['thread_id']
        );

        $this->assertSame(
            $reply->id,
            $data['reply_id']
        );

        $this->assertSame(
            route('qa-board.show', $thread, false),
            $data['url']
        );

        $this->assertNotEmpty($data['message']);
        $this->assertNotEmpty($data['body']);
    }

    public function test_notification_generates_mail_message(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $user->id,
        ]);

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
        ]);

        $notification = new QaReplyReceivedNotification($thread, $reply);

        $mail = $notification->toMail($user);

        $this->assertSame(
            '【Certify LMS】質問に返信が届きました',
            $mail->subject
        );

        $this->assertNotEmpty($mail->actionText);
        $this->assertSame(
            route('qa-board.show', $thread),
            $mail->actionUrl
        );
    }
}
