<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'テストのお知らせ',
            'body' => '配信テストの本文です。',
            'target_type' => AnnouncementTargetType::AllStudents->value,
        ], $overrides);
    }

    public function test_admin_can_dispatch_to_all_active_students_only(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $firstStudent = User::factory()->student()->inProgress()->create();
        $secondStudent = User::factory()->student()->inProgress()->create();
        $graduatedStudent = User::factory()->student()->graduated()->create();
        $coach = User::factory()->coach()->create();

        $this->actingAs($admin)
            ->post(route('admin.announcements.store'), $this->payload())
            ->assertRedirect();

        $announcement = Announcement::query()->sole();

        $this->assertSame(2, $announcement->dispatched_count);
        $this->assertSame($admin->id, $announcement->created_by);
        $this->assertSame(AnnouncementTargetType::AllStudents, $announcement->target_type);
        $this->assertNotNull($announcement->dispatched_at);

        Notification::assertSentTo([$firstStudent, $secondStudent], AdminAnnouncementNotification::class);
        Notification::assertNotSentTo([$graduatedStudent, $coach, $admin], AdminAnnouncementNotification::class);
        Notification::assertCount(2);
    }

    public function test_admin_can_dispatch_to_students_enrolled_in_selected_certification_only(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $targetCertification = Certification::factory()->published()->create();
        $otherCertification = Certification::factory()->published()->create();
        $targetStudent = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $graduatedStudent = User::factory()->student()->graduated()->create();

        Enrollment::factory()->create([
            'user_id' => $targetStudent->id,
            'certification_id' => $targetCertification->id,
        ]);
        Enrollment::factory()->create([
            'user_id' => $otherStudent->id,
            'certification_id' => $otherCertification->id,
        ]);
        Enrollment::factory()->create([
            'user_id' => $graduatedStudent->id,
            'certification_id' => $targetCertification->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.announcements.store'), $this->payload([
                'target_type' => AnnouncementTargetType::Certification->value,
                'target_certification_id' => $targetCertification->id,
            ]))
            ->assertRedirect();

        $announcement = Announcement::query()->sole();

        $this->assertSame(1, $announcement->dispatched_count);
        $this->assertSame($targetCertification->id, $announcement->target_certification_id);
        Notification::assertSentTo($targetStudent, AdminAnnouncementNotification::class);
        Notification::assertNotSentTo([$otherStudent, $graduatedStudent], AdminAnnouncementNotification::class);
        Notification::assertCount(1);
    }

    public function test_admin_can_dispatch_to_one_student_only(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $targetStudent = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();

        $this->actingAs($admin)
            ->post(route('admin.announcements.store'), $this->payload([
                'target_type' => AnnouncementTargetType::User->value,
                'target_user_id' => $targetStudent->id,
            ]))
            ->assertRedirect();

        $announcement = Announcement::query()->sole();

        $this->assertSame(1, $announcement->dispatched_count);
        $this->assertSame($targetStudent->id, $announcement->target_user_id);
        Notification::assertSentTo($targetStudent, AdminAnnouncementNotification::class);
        Notification::assertNotSentTo($otherStudent, AdminAnnouncementNotification::class);
        Notification::assertCount(1);
    }

    public function test_draft_certification_cannot_be_selected(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $draftCertification = Certification::factory()->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.announcements.store'), $this->payload([
                'target_type' => AnnouncementTargetType::Certification->value,
                'target_certification_id' => $draftCertification->id,
            ]))
            ->assertSessionHasErrors('target_certification_id');

        $this->assertDatabaseCount('announcements', 0);
        Notification::assertNothingSent();
    }

    public function test_required_fields_are_validated(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.announcements.store'), $this->payload([
                'title' => '',
                'body' => '',
                'target_type' => 'invalid',
            ]))
            ->assertSessionHasErrors(['title', 'body', 'target_type']);

        $this->assertDatabaseCount('announcements', 0);
        Notification::assertNothingSent();
    }

    public function test_coach_cannot_dispatch_announcements(): void
    {
        Notification::fake();

        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->post(route('admin.announcements.store'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('announcements', 0);
        Notification::assertNothingSent();
    }
}
