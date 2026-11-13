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

class DestroyTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_admin_can_soft_delete_draft_part(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($admin)
            ->delete(route('admin.parts.destroy', $part))
            ->assertRedirect(route('admin.certifications.parts.index', $cert));

        $this->assertDatabaseMissing('parts', [
            'id' => $part->id,
        ]);
    }

    public function test_cannot_delete_published_part(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.parts.destroy', $part))
            ->assertStatus(409);

        $this->assertDatabaseHas('parts', [
            'id' => $part->id,
        ]);
    }

    public function test_assigned_coach_can_delete_draft_part(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->delete(route('admin.parts.destroy', $part))
            ->assertRedirect(route('admin.certifications.parts.index', $cert));

        $this->assertDatabaseMissing('parts', [
            'id' => $part->id,
        ]);
    }

    public function test_unassigned_coach_cannot_delete_part(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($coach)
            ->delete(route('admin.parts.destroy', $part))
            ->assertForbidden();

        $this->assertDatabaseHas('parts', [
            'id' => $part->id,
        ]);
    }

    public function test_revoked_coach_cannot_delete_part(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $cert->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now()->subDay(),
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->delete(route('admin.parts.destroy', $part))
            ->assertForbidden();

        $this->assertDatabaseHas('parts', [
            'id' => $part->id,
        ]);
    }

    public function test_student_cannot_delete_part(): void
    {
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($student)
            ->delete(route('admin.parts.destroy', $part))
            ->assertForbidden();

        $this->assertDatabaseHas('parts', [
            'id' => $part->id,
        ]);
    }
}
