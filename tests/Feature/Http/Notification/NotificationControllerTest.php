<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * テスト用の通知を作成する。
     */
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

    public function test_authenticated_user_can_view_notifications(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $this->createNotification($user);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertViewIs('notifications.index')
            ->assertSee('テスト通知');
    }

    public function test_guest_cannot_view_notifications(): void
    {
        $this->get(route('notifications.index'))
            ->assertRedirect();
    }

    public function test_user_cannot_view_other_users_notifications(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();

        $this->createNotification($other, '他人専用の通知');

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('他人専用の通知');
    }

    public function test_unread_tab_only_displays_unread_notifications(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $this->createNotification($user, '未読の通知');
        $this->createNotification(
            $user,
            '既読の通知',
            now()->toDateTimeString(),
        );

        $this->actingAs($user)
            ->get(route('notifications.index', ['tab' => 'unread']))
            ->assertOk()
            ->assertSee('未読の通知')
            ->assertDontSee('既読の通知');
    }

    public function test_user_can_mark_own_notification_as_read(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $notificationId = $this->createNotification($user);

        $this->actingAs($user)
            ->post(route('notifications.markAsRead', $notificationId))
            ->assertRedirect(route('notifications.index'));

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
            ->post(route('notifications.markAsRead', $notificationId))
            ->assertNotFound();

        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'read_at' => null,
        ]);
    }

    public function test_mark_all_as_read_only_updates_own_notifications(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();

        $ownNotificationId = $this->createNotification($user);
        $otherNotificationId = $this->createNotification($other);

        $this->actingAs($user)
            ->post(route('notifications.markAllAsRead'))
            ->assertRedirect(route('notifications.index'));

        $this->assertDatabaseMissing('notifications', [
            'id' => $ownNotificationId,
            'read_at' => null,
        ]);

        $this->assertDatabaseHas('notifications', [
            'id' => $otherNotificationId,
            'read_at' => null,
        ]);
    }

    public function test_notification_redirects_to_related_page(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $notificationId = $this->createNotification(
            $user,
            '遷移テスト',
            null,
            '/dashboard',
        );

        $this->actingAs($user)
            ->post(route('notifications.markAsRead', $notificationId))
            ->assertRedirect('/dashboard');
    }

    public function test_authenticated_user_can_fetch_notification_feed_as_json(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $notificationId = $this->createNotification(
            $user,
            'JSON取得テスト',
            null,
            '/dashboard',
        );

        $this->actingAs($user)
            ->getJson(route('notifications.feed'))
            ->assertOk()
            ->assertJsonPath('tab', 'all')
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', $notificationId)
            ->assertJsonPath('notifications.0.title', 'JSON取得テスト')
            ->assertJsonPath('notifications.0.message', 'テスト用の通知です。')
            ->assertJsonPath(
                'notifications.0.url',
                route('notifications.markAsRead', $notificationId),
            )
            ->assertJsonPath('notifications.0.read_at', null)
            ->assertJsonStructure([
                'notifications' => [
                    '*' => ['id', 'title', 'message', 'url', 'read_at', 'created_at'],
                ],
                'unread_count',
                'tab',
            ]);
    }

    public function test_notification_feed_unread_tab_only_returns_unread_notifications(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $unreadId = $this->createNotification($user, '未読の通知');
        $this->createNotification(
            $user,
            '既読の通知',
            now()->toDateTimeString(),
        );

        $this->actingAs($user)
            ->getJson(route('notifications.feed', ['tab' => 'unread']))
            ->assertOk()
            ->assertJsonPath('tab', 'unread')
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', $unreadId)
            ->assertJsonMissing(['title' => '既読の通知']);
    }

    public function test_notification_feed_does_not_return_other_users_notifications(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();

        $this->createNotification($user, '自分の通知');
        $this->createNotification($other, '他人専用の通知');

        $this->actingAs($user)
            ->getJson(route('notifications.feed'))
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.title', '自分の通知')
            ->assertJsonMissing(['title' => '他人専用の通知']);
    }

    public function test_guest_cannot_fetch_notification_feed(): void
    {
        $this->getJson(route('notifications.feed'))
            ->assertUnauthorized();
    }

    public function test_notification_index_paginates_twenty_items(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        for ($i = 1; $i <= 21; $i++) {
            $this->createNotification($user, "通知{$i}");
        }

        $response = $this->actingAs($user)
            ->get(route('notifications.index'));

        $response
            ->assertOk()
            ->assertViewHas('notifications', function ($notifications): bool {
                return $notifications->count() === 20
                    && $notifications->perPage() === 20
                    && $notifications->total() === 21
                    && $notifications->lastPage() === 2;
            });
    }

    public function test_notification_index_displays_remaining_item_on_second_page(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        for ($i = 1; $i <= 21; $i++) {
            $this->createNotification($user, "通知{$i}");
        }

        $this->actingAs($user)
            ->get(route('notifications.index', ['page' => 2]))
            ->assertOk()
            ->assertViewHas('notifications', function ($notifications): bool {
                return $notifications->count() === 1
                    && $notifications->currentPage() === 2
                    && $notifications->total() === 21;
            });
    }

    public function test_notification_feed_displays_newest_twenty_items_first(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $oldestId = null;
        $newestId = null;

        for ($i = 1; $i <= 21; $i++) {
            $id = $this->createNotification($user, "通知{$i}");

            $user->notifications()
                ->where('id', $id)
                ->update([
                    'created_at' => now()->subMinutes(21 - $i),
                ]);

            if ($i === 1) {
                $oldestId = $id;
            }

            if ($i === 21) {
                $newestId = $id;
            }
        }

        $response = $this->actingAs($user)
            ->getJson(route('notifications.feed'))
            ->assertOk()
            ->assertJsonPath('unread_count', 21)
            ->assertJsonCount(20, 'notifications')
            ->assertJsonPath('notifications.0.id', $newestId);

        $response->assertJsonMissing([
            'id' => $oldestId,
        ]);
    }
}
