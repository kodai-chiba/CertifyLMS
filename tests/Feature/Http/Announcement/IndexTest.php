<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    private function createAnnouncement(User $admin, string $title, int $minutesAgo = 0): Announcement
    {
        return Announcement::create([
            'title' => $title,
            'body' => '配信履歴一覧のテスト本文です。',
            'target_type' => AnnouncementTargetType::AllStudents,
            'created_by' => $admin->id,
            'dispatched_count' => 1,
            'dispatched_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    public function test_admin_can_view_announcement_history(): void
    {
        $admin = User::factory()->admin()->create();
        $announcement = $this->createAnnouncement($admin, '一覧表示テスト');

        $this->actingAs($admin)
            ->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertSee('一覧表示テスト')
            ->assertSee(route('admin.announcements.show', $announcement));
    }

    public function test_announcements_are_displayed_newest_first(): void
    {
        $admin = User::factory()->admin()->create();
        $this->createAnnouncement($admin, '古いお知らせ', 60);
        $this->createAnnouncement($admin, '新しいお知らせ', 0);

        $this->actingAs($admin)
            ->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertSeeInOrder(['新しいお知らせ', '古いお知らせ']);
    }

    public function test_announcement_history_is_paginated_by_twenty(): void
    {
        $admin = User::factory()->admin()->create();

        for ($i = 1; $i <= 21; $i++) {
            $this->createAnnouncement($admin, sprintf('配信履歴%02d', $i), 21 - $i);
        }

        $this->actingAs($admin)
            ->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertViewHas('announcements', function ($announcements): bool {
                return $announcements->perPage() === 20
                    && $announcements->total() === 21
                    && $announcements->count() === 20;
            });

        $this->actingAs($admin)
            ->get(route('admin.announcements.index', ['page' => 2]))
            ->assertOk()
            ->assertViewHas('announcements', function ($announcements): bool {
                return $announcements->currentPage() === 2
                    && $announcements->count() === 1;
            });
    }

    public function test_student_cannot_view_announcement_history(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->get(route('admin.announcements.index'))
            ->assertForbidden();
    }

    public function test_coach_cannot_view_announcement_history(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->get(route('admin.announcements.index'))
            ->assertForbidden();
    }
}
