<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QuestionCategory;

use App\Models\Certification;
use App\Models\QuestionCategory;
use App\Models\SectionQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_admin_can_create_question_category(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();

        $this->actingAs($admin)
            ->post(route('admin.certifications.question-categories.store', $cert), [
                'name' => 'テクノロジー系',
                'slug' => 'technology',
                'sort_order' => 10,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('question_categories', [
            'certification_id' => $cert->id,
            'slug' => 'technology',
        ]);
    }

    public function test_duplicate_slug_within_certification_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        QuestionCategory::factory()->forCertification($cert)->state(['slug' => 'tech'])->create();

        $this->actingAs($admin)
            ->post(route('admin.certifications.question-categories.store', $cert), [
                'name' => '別名',
                'slug' => 'tech',
            ])
            ->assertSessionHasErrors('slug');
    }

    public function test_same_slug_in_different_certification_allowed(): void
    {
        $admin = User::factory()->admin()->create();
        $certA = Certification::factory()->published()->create();
        $certB = Certification::factory()->published()->create();
        QuestionCategory::factory()->forCertification($certA)->state(['slug' => 'tech'])->create();

        $this->actingAs($admin)
            ->post(route('admin.certifications.question-categories.store', $certB), [
                'name' => 'Tech',
                'slug' => 'tech',
            ])
            ->assertRedirect();

        $this->assertSame(2, QuestionCategory::where('slug', 'tech')->count());
    }

    public function test_destroy_blocked_if_section_questions_exist(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        [, , $section] = $this->makePartChain($cert);
        $category = QuestionCategory::factory()->forCertification($cert)->create();
        SectionQuestion::factory()->forSection($section)->forCategory($category)->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.question-categories.destroy', $category))
            ->assertStatus(409);
    }

    public function test_destroy_allowed_when_no_questions(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $category = QuestionCategory::factory()->forCertification($cert)->create();

        $this->actingAs($admin)
            ->delete(route('admin.question-categories.destroy', $category))
            ->assertRedirect();

        $this->assertDatabaseMissing('question_categories', ['id' => $category->id]);
    }

    public function test_non_assigned_coach_cannot_view(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();

        $this->actingAs($coach)
            ->get(route('admin.certifications.question-categories.index', $cert))
            ->assertForbidden();
    }

    public function test_assigned_coach_can_view_question_categories(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->get(route('admin.certifications.question-categories.index', $cert))
            ->assertOk();
    }

    public function test_assigned_coach_can_create_question_category(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->post(route('admin.certifications.question-categories.store', $cert), [
                'name' => '担当コーチ作成分野',
                'slug' => 'coach-category',
                'sort_order' => 10,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('question_categories', [
            'certification_id' => $cert->id,
            'name' => '担当コーチ作成分野',
            'slug' => 'coach-category',
        ]);
    }

    public function test_assigned_coach_can_update_question_category(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $category = QuestionCategory::factory()->forCertification($cert)->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->patch(route('admin.question-categories.update', $category), [
                'name' => '担当コーチ更新分野',
                'slug' => 'updated-category',
                'sort_order' => 20,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('question_categories', [
            'id' => $category->id,
            'name' => '担当コーチ更新分野',
            'slug' => 'updated-category',
        ]);
    }

    public function test_assigned_coach_can_destroy_question_category(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $category = QuestionCategory::factory()->forCertification($cert)->create();

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->delete(route('admin.question-categories.destroy', $category))
            ->assertRedirect();

        $this->assertDatabaseMissing('question_categories', [
            'id' => $category->id,
        ]);
    }

    public function test_non_assigned_coach_cannot_manage_question_categories(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $category = QuestionCategory::factory()->forCertification($cert)->create();

        $this->actingAs($coach)
            ->postJson(route('admin.certifications.question-categories.store', $cert), [
                'name' => '不正作成',
                'slug' => 'unauthorized-category',
            ])
            ->assertForbidden();

        $this->actingAs($coach)
            ->patchJson(route('admin.question-categories.update', $category), [
                'name' => '不正更新',
                'slug' => 'unauthorized-update',
            ])
            ->assertForbidden();

        $this->actingAs($coach)
            ->deleteJson(route('admin.question-categories.destroy', $category))
            ->assertForbidden();

        $this->assertDatabaseHas('question_categories', [
            'id' => $category->id,
        ]);
    }

    public function test_revoked_coach_cannot_manage_question_categories(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $category = QuestionCategory::factory()->forCertification($cert)->create();

        $this->assignCoach($coach, $cert);

        $cert->coaches()->updateExistingPivot($coach->id, [
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->get(route('admin.certifications.question-categories.index', $cert))
            ->assertForbidden();

        $this->actingAs($coach)
            ->postJson(route('admin.certifications.question-categories.store', $cert), [
                'name' => '担当解除後の不正作成',
                'slug' => 'revoked-category',
            ])
            ->assertForbidden();

        $this->actingAs($coach)
            ->patchJson(route('admin.question-categories.update', $category), [
                'name' => '担当解除後の不正更新',
                'slug' => 'revoked-update',
            ])
            ->assertForbidden();

        $this->actingAs($coach)
            ->deleteJson(route('admin.question-categories.destroy', $category))
            ->assertForbidden();

        $this->assertDatabaseHas('question_categories', [
            'id' => $category->id,
        ]);
    }
}
