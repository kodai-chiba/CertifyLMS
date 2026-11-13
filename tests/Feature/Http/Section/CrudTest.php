<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Section;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_store_section_includes_body(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.chapters.sections.store', $chapter), [
                'title' => 'はじめに',
                'body' => "## 概要\n\n本セクションでは...",
            ])
            ->assertRedirect();

        $section = Section::where('title', 'はじめに')->firstOrFail();

        $this->assertStringContainsString('概要', $section->body);
        $this->assertSame('draft', $section->status->value);
    }

    public function test_assigned_coach_can_store_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->post(route('admin.chapters.sections.store', $chapter), [
                'title' => '担当コーチ作成Section',
                'body' => '担当コーチが作成しました。',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('sections', [
            'chapter_id' => $chapter->id,
            'title' => '担当コーチ作成Section',
            'status' => 'draft',
        ]);
    }

    public function test_unassigned_coach_cannot_store_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($coach)
            ->post(route('admin.chapters.sections.store', $chapter), [
                'title' => '担当外コーチ作成Section',
                'body' => '不正作成',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('sections', [
            'chapter_id' => $chapter->id,
            'title' => '担当外コーチ作成Section',
        ]);
    }

    public function test_revoked_coach_cannot_store_section(): void
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
            ->post(route('admin.chapters.sections.store', $chapter), [
                'title' => '担当解除済みコーチ作成Section',
                'body' => '不正作成',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('sections', [
            'chapter_id' => $chapter->id,
            'title' => '担当解除済みコーチ作成Section',
        ]);
    }

    public function test_update_updates_body(): void
    {
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(
            Certification::factory()->published()->create(),
            'draft',
        );

        $this->actingAs($admin)
            ->patch(route('admin.sections.update', $section), [
                'title' => '新タイトル',
                'body' => 'updated body',
            ])
            ->assertRedirect();

        $section->refresh();

        $this->assertSame('新タイトル', $section->title);
        $this->assertSame('updated body', $section->body);
    }

    public function test_assigned_coach_can_update_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->patch(route('admin.sections.update', $section), [
                'title' => '担当コーチ更新Section',
                'body' => '担当コーチが更新しました。',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('sections', [
            'id' => $section->id,
            'title' => '担当コーチ更新Section',
            'body' => '担当コーチが更新しました。',
        ]);
    }

    public function test_unassigned_coach_cannot_update_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $originalTitle = $section->title;

        $this->actingAs($coach)
            ->patch(route('admin.sections.update', $section), [
                'title' => '不正更新Section',
                'body' => '不正更新',
            ])
            ->assertForbidden();

        $this->assertSame($originalTitle, $section->fresh()->title);
    }

    public function test_revoked_coach_cannot_update_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $this->assignCoach($coach, $cert);

        $cert->coaches()->updateExistingPivot($coach->id, [
            'unassigned_at' => now(),
        ]);

        $originalTitle = $section->title;

        $this->actingAs($coach)
            ->patch(route('admin.sections.update', $section), [
                'title' => '担当解除後の不正更新Section',
                'body' => '不正更新',
            ])
            ->assertForbidden();

        $this->assertSame($originalTitle, $section->fresh()->title);
    }

    public function test_preview_returns_html(): void
    {
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(
            Certification::factory()->published()->create(),
            'draft',
        );

        $this->actingAs($admin)
            ->postJson(route('admin.sections.preview', $section), [
                'body' => "# タイトル\n\n本文",
            ])
            ->assertOk()
            ->assertJsonStructure(['html']);
    }

    public function test_assigned_coach_can_preview_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->postJson(route('admin.sections.preview', $section), [
                'body' => '# 担当コーチプレビュー',
            ])
            ->assertOk()
            ->assertJsonStructure(['html']);
    }

    public function test_unassigned_coach_cannot_preview_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $this->actingAs($coach)
            ->postJson(route('admin.sections.preview', $section), [
                'body' => '# 不正プレビュー',
            ])
            ->assertForbidden();
    }

    public function test_revoked_coach_cannot_preview_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $this->assignCoach($coach, $cert);

        $cert->coaches()->updateExistingPivot($coach->id, [
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->postJson(route('admin.sections.preview', $section), [
                'body' => '# 担当解除後の不正プレビュー',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_destroy_draft_section(): void
    {
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(
            Certification::factory()->published()->create(),
            'draft',
        );

        $this->actingAs($admin)
            ->delete(route('admin.sections.destroy', $section))
            ->assertRedirect();

        $this->assertDatabaseMissing('sections', [
            'id' => $section->id,
        ]);
    }

    public function test_assigned_coach_can_destroy_draft_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->delete(route('admin.sections.destroy', $section))
            ->assertRedirect();

        $this->assertDatabaseMissing('sections', [
            'id' => $section->id,
        ]);
    }

    public function test_unassigned_coach_cannot_destroy_draft_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $this->actingAs($coach)
            ->delete(route('admin.sections.destroy', $section))
            ->assertForbidden();

        $this->assertDatabaseHas('sections', [
            'id' => $section->id,
            'chapter_id' => $chapter->id,
        ]);
    }

    public function test_revoked_coach_cannot_destroy_draft_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $this->assignCoach($coach, $cert);

        $cert->coaches()->updateExistingPivot($coach->id, [
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->delete(route('admin.sections.destroy', $section))
            ->assertForbidden();

        $this->assertDatabaseHas('sections', [
            'id' => $section->id,
            'chapter_id' => $chapter->id,
        ]);
    }

    public function test_admin_can_publish_draft_section(): void
    {
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(
            Certification::factory()->published()->create(),
            'draft',
        );

        $this->actingAs($admin)
            ->post(route('admin.sections.publish', $section))
            ->assertRedirect();

        $section->refresh();

        $this->assertSame('published', $section->status->value);
        $this->assertNotNull($section->published_at);
    }

    public function test_assigned_coach_can_publish_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->post(route('admin.sections.publish', $section))
            ->assertRedirect();

        $section->refresh();

        $this->assertSame('published', $section->status->value);
        $this->assertNotNull($section->published_at);
    }

    public function test_unassigned_coach_cannot_publish_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $this->actingAs($coach)
            ->post(route('admin.sections.publish', $section))
            ->assertForbidden();

        $this->assertSame('draft', $section->fresh()->status->value);
    }

    public function test_revoked_coach_cannot_publish_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $this->assignCoach($coach, $cert);

        $cert->coaches()->updateExistingPivot($coach->id, [
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->post(route('admin.sections.publish', $section))
            ->assertForbidden();

        $this->assertSame('draft', $section->fresh()->status->value);
    }

    public function test_admin_can_unpublish_section(): void
    {
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(
            Certification::factory()->published()->create(),
            'published',
        );

        $this->actingAs($admin)
            ->post(route('admin.sections.unpublish', $section))
            ->assertRedirect();

        $this->assertSame('draft', $section->fresh()->status->value);
    }

    public function test_assigned_coach_can_unpublish_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'published');

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->post(route('admin.sections.unpublish', $section))
            ->assertRedirect();

        $this->assertSame('draft', $section->fresh()->status->value);
    }

    public function test_unassigned_coach_cannot_unpublish_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'published');

        $this->actingAs($coach)
            ->post(route('admin.sections.unpublish', $section))
            ->assertForbidden();

        $this->assertSame('published', $section->fresh()->status->value);
    }

    public function test_revoked_coach_cannot_unpublish_section(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'published');

        $this->assignCoach($coach, $cert);

        $cert->coaches()->updateExistingPivot($coach->id, [
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->post(route('admin.sections.unpublish', $section))
            ->assertForbidden();

        $this->assertSame('published', $section->fresh()->status->value);
    }

    public function test_cannot_destroy_published_section(): void
    {
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(
            Certification::factory()->published()->create(),
            'published',
        );

        $this->actingAs($admin)
            ->deleteJson(route('admin.sections.destroy', $section))
            ->assertStatus(409);

        $this->assertDatabaseHas('sections', [
            'id' => $section->id,
        ]);
    }

    public function test_cannot_publish_already_published_section(): void
    {
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(
            Certification::factory()->published()->create(),
            'published',
        );

        $this->actingAs($admin)
            ->postJson(route('admin.sections.publish', $section))
            ->assertStatus(409);

        $this->assertSame('published', $section->fresh()->status->value);
    }

    public function test_cannot_unpublish_already_draft_section(): void
    {
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(
            Certification::factory()->published()->create(),
            'draft',
        );

        $this->actingAs($admin)
            ->postJson(route('admin.sections.unpublish', $section))
            ->assertStatus(409);

        $this->assertSame('draft', $section->fresh()->status->value);
    }
}
