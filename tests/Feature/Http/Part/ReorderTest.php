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

class ReorderTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_reorder_updates_order_columns(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $a = Part::factory()->forCertification($cert)->state(['order' => 1])->create();
        $b = Part::factory()->forCertification($cert)->state(['order' => 2])->create();
        $c = Part::factory()->forCertification($cert)->state(['order' => 3])->create();

        $this->actingAs($admin)
            ->patch(route('admin.certifications.parts.reorder', $cert), [
                'ids' => [$c->id, $a->id, $b->id],
            ])
            ->assertRedirect();

        $this->assertSame(1, $c->fresh()->order);
        $this->assertSame(2, $a->fresh()->order);
        $this->assertSame(3, $b->fresh()->order);
    }

    public function test_reorder_rejects_cross_certification_ids(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $otherCert = Certification::factory()->published()->create();
        $a = Part::factory()->forCertification($cert)->state(['order' => 1])->create();
        $foreign = Part::factory()->forCertification($otherCert)->state(['order' => 1])->create();

        $this->actingAs($admin)
            ->patchJson(route('admin.certifications.parts.reorder', $cert), [
                'ids' => [$a->id, $foreign->id],
            ])
            ->assertStatus(422);
    }

    public function test_assigned_coach_can_reorder_parts(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $a = Part::factory()->forCertification($cert)->state(['order' => 1])->create();
        $b = Part::factory()->forCertification($cert)->state(['order' => 2])->create();
        $c = Part::factory()->forCertification($cert)->state(['order' => 3])->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->patch(route('admin.certifications.parts.reorder', $cert), [
                'ids' => [$c->id, $a->id, $b->id],
            ])
            ->assertRedirect();

        $this->assertSame(1, $c->fresh()->order);
        $this->assertSame(2, $a->fresh()->order);
        $this->assertSame(3, $b->fresh()->order);
    }

    public function test_unassigned_coach_cannot_reorder_parts(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $a = Part::factory()->forCertification($cert)->state(['order' => 1])->create();
        $b = Part::factory()->forCertification($cert)->state(['order' => 2])->create();

        $this->actingAs($coach)
            ->patch(route('admin.certifications.parts.reorder', $cert), [
                'ids' => [$b->id, $a->id],
            ])
            ->assertForbidden();

        $this->assertSame(1, $a->fresh()->order);
        $this->assertSame(2, $b->fresh()->order);
    }

    public function test_revoked_coach_cannot_reorder_parts(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $a = Part::factory()->forCertification($cert)->state(['order' => 1])->create();
        $b = Part::factory()->forCertification($cert)->state(['order' => 2])->create();

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $cert->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now()->subDay(),
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->patch(route('admin.certifications.parts.reorder', $cert), [
                'ids' => [$b->id, $a->id],
            ])
            ->assertForbidden();

        $this->assertSame(1, $a->fresh()->order);
        $this->assertSame(2, $b->fresh()->order);
    }

    public function test_student_cannot_reorder_parts(): void
    {
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        $a = Part::factory()->forCertification($cert)->state(['order' => 1])->create();
        $b = Part::factory()->forCertification($cert)->state(['order' => 2])->create();

        $this->actingAs($student)
            ->patch(route('admin.certifications.parts.reorder', $cert), [
                'ids' => [$b->id, $a->id],
            ])
            ->assertForbidden();

        $this->assertSame(1, $a->fresh()->order);
        $this->assertSame(2, $b->fresh()->order);
    }
}
