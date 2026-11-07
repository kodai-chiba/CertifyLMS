<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createNotification(
        User $user,
        string $title = 'テスト通知',
        ?string $readAt = null,
        ?string $url = null,
    ): string {
        $notification = $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test_notification',
            'data' => [
                'notification_type' => 'test',
                'title' => $title,
                'message' => 'テスト用の通知です。',
                'url' => $url,
            ],
            'read_at' => $readAt,
        ]);

        return $notification->id;
    }

    public function test_student_can_fetch_own_notifications_as_json(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $notificationId = $this->createNotification(
            $user,
            'API取得テスト',
            null,
            '/dashboard',
        );

        $this->actingAs($user)
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonPath('tab', 'all')
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', $notificationId)
            ->assertJsonPath('notifications.0.title', 'API取得テスト')
            ->assertJsonPath('notifications.0.message', 'テスト用の通知です。')
            ->assertJsonPath('notifications.0.url', '/dashboard')
            ->assertJsonPath('notifications.0.read_at', null)
            ->assertJsonStructure([
                'notifications' => [
                    '*' => [
                        'id',
                        'title',
                        'message',
                        'url',
                        'read_at',
                        'created_at',
                    ],
                ],
                'unread_count',
                'tab',
                'pagination' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ]);
    }

    public function test_coach_can_fetch_own_notifications_as_json(): void
    {
        $coach = User::factory()->coach()->create();

        $this->createNotification($coach);

        $this->actingAs($coach)
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonCount(1, 'notifications');
    }

    public function test_admin_cannot_access_notification_api(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->getJson(route('api.v1.notifications.index'))
            ->assertForbidden();
    }

    public function test_guest_cannot_access_notification_api(): void
    {
        $this->getJson(route('api.v1.notifications.index'))
            ->assertUnauthorized();
    }

    public function test_api_only_returns_own_notifications(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();

        $this->createNotification($user, '自分の通知');
        $this->createNotification($other, '他人の通知');

        $this->actingAs($user)
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.title', '自分の通知')
            ->assertJsonMissing(['title' => '他人の通知']);
    }

    public function test_api_unread_tab_only_returns_unread_notifications(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $this->createNotification($user, '未読通知');
        $this->createNotification(
            $user,
            '既読通知',
            now()->toDateTimeString(),
        );

        $this->actingAs($user)
            ->getJson(route('api.v1.notifications.index', [
                'tab' => 'unread',
            ]))
            ->assertOk()
            ->assertJsonPath('tab', 'unread')
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.title', '未読通知')
            ->assertJsonMissing(['title' => '既読通知']);
    }

    public function test_student_can_mark_own_notification_as_read(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $notificationId = $this->createNotification($user);

        $this->actingAs($user)
            ->postJson(route('api.v1.notifications.markAsRead', $notificationId))
            ->assertOk()
            ->assertJsonPath('message', '通知を既読にしました。')
            ->assertJsonPath('notification.id', $notificationId)
            ->assertJsonPath('unread_count', 0)
            ->assertJsonStructure([
                'notification' => [
                    'id',
                    'read_at',
                ],
            ]);

        $this->assertDatabaseMissing('notifications', [
            'id' => $notificationId,
            'read_at' => null,
        ]);
    }

    public function test_user_cannot_mark_other_users_notification_as_read(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();

        $notificationId = $this->createNotification($other);

        $this->actingAs($user)
            ->postJson(route('api.v1.notifications.markAsRead', $notificationId))
            ->assertNotFound();

        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'read_at' => null,
        ]);
    }

    public function test_student_can_mark_all_own_notifications_as_read(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();

        $ownNotificationId1 = $this->createNotification($user);
        $ownNotificationId2 = $this->createNotification($user);
        $otherNotificationId = $this->createNotification($other);

        $this->actingAs($user)
            ->postJson(route('api.v1.notifications.markAllAsRead'))
            ->assertOk()
            ->assertJsonPath('message', 'すべての通知を既読にしました。')
            ->assertJsonPath('updated_count', 2)
            ->assertJsonPath('unread_count', 0);

        $this->assertDatabaseMissing('notifications', [
            'id' => $ownNotificationId1,
            'read_at' => null,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'id' => $ownNotificationId2,
            'read_at' => null,
        ]);

        $this->assertDatabaseHas('notifications', [
            'id' => $otherNotificationId,
            'read_at' => null,
        ]);
    }

    public function test_admin_cannot_mark_notifications_as_read(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson(route('api.v1.notifications.markAllAsRead'))
            ->assertForbidden();
    }

    public function test_external_notification_url_is_not_returned(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $this->createNotification(
            $user,
            '外部URLテスト',
            null,
            'https://example.com',
        );

        $this->actingAs($user)
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonPath('notifications.0.url', null);
    }
}
