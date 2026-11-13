<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Chapter;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

/**
 * Chapter 詳細画面(ChapterController::show)の検証。
 * Chapter 詳細に表示される配下 Section 一覧の並び順と HTTP 認可を確認する。
 */
class ShowTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_sections_are_listed_in_order_ascending(): void
    {
        // Arrange: order を登録順とずらして Section を作成
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();

        Section::factory()->forChapter($chapter)->published()->create(['order' => 3]);
        Section::factory()->forChapter($chapter)->published()->create(['order' => 1]);
        Section::factory()->forChapter($chapter)->published()->create(['order' => 2]);

        // Act
        $response = $this->actingAs($admin)
            ->get(route('admin.chapters.show', $chapter));

        // Assert: 登録順(3,1,2)ではなく order 昇順(1,2,3)で並ぶ
        $response->assertOk();

        $this->assertSame(
            [1, 2, 3],
            $response->viewData('chapter')->sections->pluck('order')->all(),
            'Chapter 詳細の Section 一覧は order 昇順で並ぶはず(登録順ではない)',
        );
    }

    public function test_admin_can_view_chapter(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();

        $this->actingAs($admin)
            ->get(route('admin.chapters.show', $chapter))
            ->assertOk();
    }

    public function test_assigned_coach_can_view_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->get(route('admin.chapters.show', $chapter))
            ->assertOk();
    }

    public function test_unassigned_coach_cannot_view_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();

        $this->actingAs($coach)
            ->get(route('admin.chapters.show', $chapter))
            ->assertForbidden();
    }

    public function test_revoked_coach_cannot_view_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $cert->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now()->subDay(),
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->get(route('admin.chapters.show', $chapter))
            ->assertForbidden();
    }

    public function test_student_cannot_view_admin_chapter(): void
    {
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();

        $this->actingAs($student)
            ->get(route('admin.chapters.show', $chapter))
            ->assertForbidden();
    }
}
