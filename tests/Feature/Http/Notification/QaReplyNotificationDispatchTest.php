<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class QaReplyNotificationDispatchTest extends TestCase
{
    use DatabaseMigrations;

    public function test_coach_reply_notifies_thread_owner(): void
    {
        Notification::fake();

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
        ]);

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $student->id,
        ]);

        $this->actingAs($coach)
            ->post(route('qa-board.replies.store', $thread), [
                'body' => 'コーチからの回答です。',
            ])
            ->assertRedirect(route('qa-board.show', $thread));

        Notification::assertSentTo(
            $student,
            QaReplyReceivedNotification::class
        );

        Notification::assertNotSentTo(
            $coach,
            QaReplyReceivedNotification::class
        );
    }

    public function test_own_reply_does_not_send_notification(): void
    {
        Notification::fake();

        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $student->id,
        ]);

        $this->actingAs($student)
            ->post(route('qa-board.replies.store', $thread), [
                'body' => '自分の質問への返信です。',
            ])
            ->assertRedirect(route('qa-board.show', $thread));

        Notification::assertNothingSent();
    }

    public function test_unrelated_user_does_not_receive_notification(): void
    {
        Notification::fake();

        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
        ]);

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $student->id,
        ]);

        $this->actingAs($coach)
            ->post(route('qa-board.replies.store', $thread), [
                'body' => '質問への返信です。',
            ])
            ->assertRedirect(route('qa-board.show', $thread));

        Notification::assertSentTo(
            $student,
            QaReplyReceivedNotification::class
        );

        Notification::assertNotSentTo(
            $otherStudent,
            QaReplyReceivedNotification::class
        );
    }
}
