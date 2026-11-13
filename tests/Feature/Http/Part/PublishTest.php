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

class PublishTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_admin_can_publish_draft_part(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.parts.publish', $part))
            ->assertRedirect(route('admin.parts.show', $part));

        $part->refresh();
        $this->assertSame('published', $part->status->value);
        $this->assertNotNull($part->published_at);
    }

    public function test_cannot_publish_already_published(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();

        $this->actingAs($admin)
            ->postJson(route('admin.parts.publish', $part))
            ->assertStatus(409);
    }

    public function test_admin_can_unpublish_published_part(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();

        $this->actingAs($admin)
            ->post(route('admin.parts.unpublish', $part))
            ->assertRedirect(route('admin.parts.show', $part));

        $this->assertSame('draft', $part->fresh()->status->value);
    }

    public function test_assigned_coach_can_publish_part(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->post(route('admin.parts.publish', $part))
            ->assertRedirect(route('admin.parts.show', $part));

        $part->refresh();

        $this->assertSame('published', $part->status->value);
        $this->assertNotNull($part->published_at);
    }

    public function test_assigned_coach_can_unpublish_part(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->post(route('admin.parts.unpublish', $part))
            ->assertRedirect(route('admin.parts.show', $part));

        $this->assertSame('draft', $part->fresh()->status->value);
    }

    public function test_unassigned_coach_cannot_publish_part(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($coach)
            ->post(route('admin.parts.publish', $part))
            ->assertForbidden();

        $this->assertSame('draft', $part->fresh()->status->value);
    }

    public function test_unassigned_coach_cannot_unpublish_part(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();

        $this->actingAs($coach)
            ->post(route('admin.parts.unpublish', $part))
            ->assertForbidden();

        $this->assertSame('published', $part->fresh()->status->value);
    }

    public function test_revoked_coach_cannot_publish_part(): void
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
            ->post(route('admin.parts.publish', $part))
            ->assertForbidden();

        $this->assertSame('draft', $part->fresh()->status->value);
    }

    public function test_revoked_coach_cannot_unpublish_part(): void
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
            ->post(route('admin.parts.unpublish', $part))
            ->assertForbidden();

        $this->assertSame('published', $part->fresh()->status->value);
    }

    public function test_student_cannot_publish_part(): void
    {
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($student)
            ->post(route('admin.parts.publish', $part))
            ->assertForbidden();

        $this->assertSame('draft', $part->fresh()->status->value);
    }

    public function test_student_cannot_unpublish_part(): void
    {
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();

        $this->actingAs($student)
            ->post(route('admin.parts.unpublish', $part))
            ->assertForbidden();

        $this->assertSame('published', $part->fresh()->status->value);
    }
}
