<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use RefreshDatabase;

    private function createAnnouncement(User $admin): Announcement
    {
        return Announcement::create([
            'title' => '【テスト】お知らせ詳細',
            'body' => "お知らせの本文です。\n2行目の内容です。",
            'target_type' => AnnouncementTargetType::AllStudents,
            'created_by' => $admin->id,
            'dispatched_count' => 3,
            'dispatched_at' => now(),
        ]);
    }

    public function test_admin_can_view_announcement_detail(): void
    {
        $admin = User::factory()->admin()->create();
        $announcement = $this->createAnnouncement($admin);

        $this->actingAs($admin)
            ->get(route('admin.announcements.show', $announcement))
            ->assertOk()
            ->assertSee('【テスト】お知らせ詳細')
            ->assertSee('お知らせの本文です。')
            ->assertSee('2行目の内容です。')
            ->assertSee('全受講生')
            ->assertSee('3 件')
            ->assertSee($admin->name)
            ->assertSee($announcement->dispatched_at->format('Y/m/d H:i'));
    }

    public function test_student_cannot_view_announcement_detail(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();
        $announcement = $this->createAnnouncement($admin);

        $this->actingAs($student)
            ->get(route('admin.announcements.show', $announcement))
            ->assertForbidden();
    }

    public function test_coach_cannot_view_announcement_detail(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $announcement = $this->createAnnouncement($admin);

        $this->actingAs($coach)
            ->get(route('admin.announcements.show', $announcement))
            ->assertForbidden();
    }

    public function test_nonexistent_announcement_returns_not_found(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route(
                'admin.announcements.show',
                '01aaaaaaaaaaaaaaaaaaaaaaaa'
            ))
            ->assertNotFound();
    }

    public function test_announcement_body_is_html_escaped(): void
    {
        $admin = User::factory()->admin()->create();
        $announcement = $this->createAnnouncement($admin);

        $announcement->update([
            'body' => '<script>alert("XSS")</script>',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.announcements.show', $announcement))
            ->assertOk()
            ->assertDontSee('<script>alert("XSS")</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }
}
