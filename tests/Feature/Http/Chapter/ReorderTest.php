<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Chapter;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class ReorderTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_admin_can_reorder_chapters(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $chapter1 = Chapter::factory()->forPart($part)->draft()->create(['order' => 1]);
        $chapter2 = Chapter::factory()->forPart($part)->draft()->create(['order' => 2]);

        $this->actingAs($admin)
            ->patch(route('admin.parts.chapters.reorder', $part), [
                'ids' => [$chapter2->id, $chapter1->id],
            ])
            ->assertRedirect();

        $this->assertSame(2, $chapter1->fresh()->order);
        $this->assertSame(1, $chapter2->fresh()->order);
    }

    public function test_assigned_coach_can_reorder_chapters(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $chapter1 = Chapter::factory()->forPart($part)->draft()->create(['order' => 1]);
        $chapter2 = Chapter::factory()->forPart($part)->draft()->create(['order' => 2]);

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->patch(route('admin.parts.chapters.reorder', $part), [
                'ids' => [$chapter2->id, $chapter1->id],
            ])
            ->assertRedirect();

        $this->assertSame(2, $chapter1->fresh()->order);
        $this->assertSame(1, $chapter2->fresh()->order);
    }

    public function test_unassigned_coach_cannot_reorder_chapters(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $chapter1 = Chapter::factory()->forPart($part)->draft()->create(['order' => 1]);
        $chapter2 = Chapter::factory()->forPart($part)->draft()->create(['order' => 2]);

        $this->actingAs($coach)
            ->patch(route('admin.parts.chapters.reorder', $part), [
                'ids' => [$chapter2->id, $chapter1->id],
            ])
            ->assertForbidden();

        $this->assertSame(1, $chapter1->fresh()->order);
        $this->assertSame(2, $chapter2->fresh()->order);
    }

    public function test_revoked_coach_cannot_reorder_chapters(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $chapter1 = Chapter::factory()->forPart($part)->draft()->create(['order' => 1]);
        $chapter2 = Chapter::factory()->forPart($part)->draft()->create(['order' => 2]);

        $this->assignCoach($coach, $cert);

        $cert->coaches()->updateExistingPivot($coach->id, [
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->patch(route('admin.parts.chapters.reorder', $part), [
                'ids' => [$chapter2->id, $chapter1->id],
            ])
            ->assertForbidden();

        $this->assertSame(1, $chapter1->fresh()->order);
        $this->assertSame(2, $chapter2->fresh()->order);
    }

    public function test_cannot_reorder_chapter_from_another_part(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();

        $part1 = Part::factory()->forCertification($cert)->draft()->create();
        $part2 = Part::factory()->forCertification($cert)->draft()->create();

        $chapter1 = Chapter::factory()->forPart($part1)->draft()->create(['order' => 1]);
        $chapter2 = Chapter::factory()->forPart($part2)->draft()->create(['order' => 1]);

        $this->actingAs($admin)
            ->patchJson(route('admin.parts.chapters.reorder', $part1), [
                'ids' => [$chapter1->id, $chapter2->id],
            ])
            ->assertStatus(422);

        $this->assertSame(1, $chapter1->fresh()->order);
        $this->assertSame(1, $chapter2->fresh()->order);
    }

    public function test_cannot_reorder_with_missing_chapter_ids(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $chapter1 = Chapter::factory()->forPart($part)->draft()->create(['order' => 1]);
        $chapter2 = Chapter::factory()->forPart($part)->draft()->create(['order' => 2]);

        $this->actingAs($admin)
            ->patchJson(route('admin.parts.chapters.reorder', $part), [
                'ids' => [$chapter1->id],
            ])
            ->assertStatus(422);

        $this->assertSame(1, $chapter1->fresh()->order);
        $this->assertSame(2, $chapter2->fresh()->order);
    }
}
