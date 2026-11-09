<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Part;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

/**
 * Part 更新時の HTTP 認可を検証する Feature テスト。
 */
class UpdateTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_admin_can_update_part(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->create();

        $this->actingAs($admin)
            ->patch(route('admin.parts.update', $part), [
                'title' => '管理者が更新したPart',
                'description' => '管理者による更新',
            ])
            ->assertRedirect(route('admin.parts.show', $part));

        $this->assertDatabaseHas('parts', [
            'id' => $part->id,
            'title' => '管理者が更新したPart',
            'description' => '管理者による更新',
        ]);
    }

    public function test_assigned_coach_can_update_part(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->patch(route('admin.parts.update', $part), [
                'title' => '担当コーチが更新したPart',
                'description' => '担当資格の教材更新',
            ])
            ->assertRedirect(route('admin.parts.show', $part));

        $this->assertDatabaseHas('parts', [
            'id' => $part->id,
            'title' => '担当コーチが更新したPart',
            'description' => '担当資格の教材更新',
        ]);
    }

    public function test_unassigned_coach_cannot_update_part(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->create([
            'title' => '更新前のPart',
        ]);

        $this->actingAs($coach)
            ->patch(route('admin.parts.update', $part), [
                'title' => '不正な更新',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('parts', [
            'id' => $part->id,
            'title' => '更新前のPart',
        ]);
    }

    public function test_revoked_coach_cannot_update_part(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->create([
            'title' => '更新前のPart',
        ]);

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $cert->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now()->subDay(),
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->patch(route('admin.parts.update', $part), [
                'title' => '不正な更新',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('parts', [
            'id' => $part->id,
            'title' => '更新前のPart',
        ]);
    }

    public function test_student_cannot_update_part(): void
    {
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->create([
            'title' => '更新前のPart',
        ]);

        $this->actingAs($student)
            ->patch(route('admin.parts.update', $part), [
                'title' => '不正な更新',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('parts', [
            'id' => $part->id,
            'title' => '更新前のPart',
        ]);
    }
}
