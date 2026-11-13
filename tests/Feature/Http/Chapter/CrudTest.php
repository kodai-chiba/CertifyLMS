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

class CrudTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_store_creates_draft_chapter(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.parts.chapters.store', $part), ['title' => '第1章'])
            ->assertRedirect();

        $chapter = Chapter::where('title', '第1章')->firstOrFail();

        $this->assertSame('draft', $chapter->status->value);
        $this->assertSame(1, $chapter->order);
    }

    public function test_assigned_coach_can_store_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->post(route('admin.parts.chapters.store', $part), [
                'title' => '担当コーチ作成Chapter',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('chapters', [
            'part_id' => $part->id,
            'title' => '担当コーチ作成Chapter',
            'status' => 'draft',
        ]);
    }

    public function test_unassigned_coach_cannot_store_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($coach)
            ->post(route('admin.parts.chapters.store', $part), [
                'title' => '担当外コーチ作成Chapter',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('chapters', [
            'part_id' => $part->id,
            'title' => '担当外コーチ作成Chapter',
        ]);
    }

    public function test_revoked_coach_cannot_store_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->assignCoach($coach, $cert);

        $cert->coaches()->updateExistingPivot($coach->id, [
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->post(route('admin.parts.chapters.store', $part), [
                'title' => '担当解除済みコーチ作成Chapter',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('chapters', [
            'part_id' => $part->id,
            'title' => '担当解除済みコーチ作成Chapter',
        ]);
    }

    public function test_admin_can_update_chapter(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($admin)
            ->patch(route('admin.chapters.update', $chapter), [
                'title' => '管理者更新Chapter',
                'description' => '管理者が更新しました。',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('chapters', [
            'id' => $chapter->id,
            'title' => '管理者更新Chapter',
            'description' => '管理者が更新しました。',
            'status' => 'draft',
        ]);
    }

    public function test_assigned_coach_can_update_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->patch(route('admin.chapters.update', $chapter), [
                'title' => '担当コーチ更新Chapter',
                'description' => '担当コーチが更新しました。',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('chapters', [
            'id' => $chapter->id,
            'title' => '担当コーチ更新Chapter',
            'description' => '担当コーチが更新しました。',
            'status' => 'draft',
        ]);
    }

    public function test_unassigned_coach_cannot_update_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $originalTitle = $chapter->title;

        $this->actingAs($coach)
            ->patch(route('admin.chapters.update', $chapter), [
                'title' => '不正更新Chapter',
            ])
            ->assertForbidden();

        $this->assertSame($originalTitle, $chapter->fresh()->title);
    }

    public function test_revoked_coach_cannot_update_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->assignCoach($coach, $cert);

        $cert->coaches()->updateExistingPivot($coach->id, [
            'unassigned_at' => now(),
        ]);

        $originalTitle = $chapter->title;

        $this->actingAs($coach)
            ->patch(route('admin.chapters.update', $chapter), [
                'title' => '担当解除後の不正更新Chapter',
            ])
            ->assertForbidden();

        $this->assertSame($originalTitle, $chapter->fresh()->title);
    }

    public function test_admin_can_destroy_draft_chapter(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($admin)
            ->delete(route('admin.chapters.destroy', $chapter))
            ->assertRedirect();

        $this->assertDatabaseMissing('chapters', [
            'id' => $chapter->id,
        ]);
    }

    public function test_assigned_coach_can_destroy_draft_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->delete(route('admin.chapters.destroy', $chapter))
            ->assertRedirect();

        $this->assertDatabaseMissing('chapters', [
            'id' => $chapter->id,
        ]);
    }

    public function test_unassigned_coach_cannot_destroy_draft_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($coach)
            ->delete(route('admin.chapters.destroy', $chapter))
            ->assertForbidden();

        $this->assertDatabaseHas('chapters', [
            'id' => $chapter->id,
            'part_id' => $part->id,
            'status' => 'draft',
        ]);
    }

    public function test_revoked_coach_cannot_destroy_draft_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->assignCoach($coach, $cert);

        $cert->coaches()->updateExistingPivot($coach->id, [
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->delete(route('admin.chapters.destroy', $chapter))
            ->assertForbidden();

        $this->assertDatabaseHas('chapters', [
            'id' => $chapter->id,
            'part_id' => $part->id,
            'status' => 'draft',
        ]);
    }

    public function test_publish_then_unpublish(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.chapters.publish', $chapter))
            ->assertRedirect();

        $this->assertSame('published', $chapter->fresh()->status->value);

        $this->actingAs($admin)
            ->post(route('admin.chapters.unpublish', $chapter))
            ->assertRedirect();

        $this->assertSame('draft', $chapter->fresh()->status->value);
    }

    public function test_assigned_coach_can_publish_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->post(route('admin.chapters.publish', $chapter))
            ->assertRedirect();

        $chapter->refresh();

        $this->assertSame('published', $chapter->status->value);
        $this->assertNotNull($chapter->published_at);
    }

    public function test_unassigned_coach_cannot_publish_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($coach)
            ->post(route('admin.chapters.publish', $chapter))
            ->assertForbidden();

        $this->assertSame('draft', $chapter->fresh()->status->value);
    }

    public function test_revoked_coach_cannot_publish_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->assignCoach($coach, $cert);

        $cert->coaches()->updateExistingPivot($coach->id, [
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->post(route('admin.chapters.publish', $chapter))
            ->assertForbidden();

        $this->assertSame('draft', $chapter->fresh()->status->value);
    }

    public function test_assigned_coach_can_unpublish_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->post(route('admin.chapters.unpublish', $chapter))
            ->assertRedirect();

        $this->assertSame('draft', $chapter->fresh()->status->value);
    }

    public function test_unassigned_coach_cannot_unpublish_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();

        $this->actingAs($coach)
            ->post(route('admin.chapters.unpublish', $chapter))
            ->assertForbidden();

        $this->assertSame('published', $chapter->fresh()->status->value);
    }

    public function test_revoked_coach_cannot_unpublish_chapter(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();

        $this->assignCoach($coach, $cert);

        $cert->coaches()->updateExistingPivot($coach->id, [
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->post(route('admin.chapters.unpublish', $chapter))
            ->assertForbidden();

        $this->assertSame('published', $chapter->fresh()->status->value);
    }

    public function test_destroy_draft_only(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.chapters.destroy', $chapter))
            ->assertStatus(409);
    }
}
