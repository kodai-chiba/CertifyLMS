<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Part;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

/**
 * Part 詳細画面(PartController::show)の検証。
 * Chapter の表示順と、担当コーチのアクセス権限を確認する。
 */
class ShowTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_chapters_are_listed_in_order_ascending(): void
    {
        // Arrange: order を登録順とずらして Chapter を作成
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();

        Chapter::factory()->forPart($part)->published()->create(['order' => 3]);
        Chapter::factory()->forPart($part)->published()->create(['order' => 1]);
        Chapter::factory()->forPart($part)->published()->create(['order' => 2]);

        // Act
        $response = $this->actingAs($admin)
            ->get(route('admin.parts.show', $part));

        // Assert: 登録順(3,1,2)ではなく order 昇順(1,2,3)で並ぶ
        $response->assertOk();

        $this->assertSame(
            [1, 2, 3],
            $response->viewData('part')->chapters->pluck('order')->all(),
            'Part 詳細の Chapter 一覧は order 昇順で並ぶはず(登録順ではない)',
        );
    }

    public function test_assigned_coach_can_view_part(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->get(route('admin.parts.show', $part))
            ->assertOk()
            ->assertViewIs('part.management.show');
    }

    public function test_unassigned_coach_cannot_view_part(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();

        $this->actingAs($coach)
            ->get(route('admin.parts.show', $part))
            ->assertForbidden();
    }

    public function test_revoked_coach_cannot_view_part(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $cert->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now()->subDay(),
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->get(route('admin.parts.show', $part))
            ->assertForbidden();
    }

    public function test_student_cannot_view_admin_part(): void
    {
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();

        $this->actingAs($student)
            ->get(route('admin.parts.show', $part))
            ->assertForbidden();
    }
}
