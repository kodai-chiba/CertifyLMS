<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExam;

use App\Models\Certification;
use App\Models\MockExam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MockExamIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_mock_exams_with_related_information(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->admin()->create();
        $updater = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create([
            'name' => '一覧表示テスト資格',
        ]);

        $mockExam = MockExam::factory()->forCertification($certification)->create([
            'title' => '一覧表示テスト模試',
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $updater->id,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.mock-exams.index'));

        $response->assertOk()
            ->assertSee('一覧表示テスト資格')
            ->assertSee('一覧表示テスト模試')
            ->assertSee($updater->name);

        $response->assertViewHas('mockExams', function ($mockExams) use ($mockExam, $creator, $updater): bool {
            $exam = $mockExams->getCollection()->firstWhere('id', $mockExam->id);

            return $exam !== null
                && $exam->relationLoaded('certification')
                && $exam->relationLoaded('createdBy')
                && $exam->relationLoaded('updatedBy')
                && $exam->createdBy?->id === $creator->id
                && $exam->updatedBy?->id === $updater->id;
        });
    }

    public function test_admin_can_filter_mock_exams_by_keyword_certification_and_publication(): void
    {
        $admin = User::factory()->admin()->create();
        $targetCertification = Certification::factory()->published()->create();
        $otherCertification = Certification::factory()->published()->create();

        $target = MockExam::factory()->forCertification($targetCertification)->create([
            'title' => '検索対象の模試',
            'is_published' => true,
        ]);

        MockExam::factory()->forCertification($targetCertification)->create([
            'title' => '検索対象の非公開模試',
            'is_published' => false,
        ]);

        MockExam::factory()->forCertification($otherCertification)->create([
            'title' => '検索対象の別資格模試',
            'is_published' => true,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.mock-exams.index', [
                'keyword' => '検索対象',
                'certification_id' => $targetCertification->id,
                'is_published' => '1',
            ]));

        $response->assertOk()
            ->assertViewHas('mockExams', function ($mockExams) use ($target): bool {
                return $mockExams->total() === 1
                    && $mockExams->first()?->id === $target->id;
            });
    }

    public function test_admin_index_preserves_existing_order(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();

        $third = MockExam::factory()->forCertification($certification)->create([
            'title' => '並び順3',
            'order' => 2,
        ]);

        $first = MockExam::factory()->forCertification($certification)->create([
            'title' => '並び順1',
            'order' => 0,
        ]);

        $second = MockExam::factory()->forCertification($certification)->create([
            'title' => '並び順2',
            'order' => 1,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.mock-exams.index'))
            ->assertOk()
            ->assertViewHas('mockExams', function ($mockExams) use ($first, $second, $third): bool {
                return $mockExams->getCollection()->pluck('id')->all() === [
                    $first->id,
                    $second->id,
                    $third->id,
                ];
            });
    }

    public function test_admin_index_orders_same_order_by_updated_at_desc(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();

        $oldest = MockExam::factory()->forCertification($certification)->create([
            'title' => '更新日時が古い模試',
            'order' => 1,
            'updated_at' => now()->subDays(3),
        ]);

        $newest = MockExam::factory()->forCertification($certification)->create([
            'title' => '更新日時が新しい模試',
            'order' => 1,
            'updated_at' => now()->subDay(),
        ]);

        $middle = MockExam::factory()->forCertification($certification)->create([
            'title' => '更新日時が中間の模試',
            'order' => 1,
            'updated_at' => now()->subDays(2),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.mock-exams.index'))
            ->assertOk()
            ->assertViewHas('mockExams', function ($mockExams) use ($newest, $middle, $oldest): bool {
                return $mockExams->getCollection()->pluck('id')->all() === [
                    $newest->id,
                    $middle->id,
                    $oldest->id,
                ];
            });
    }

    public function test_admin_index_paginates_at_twenty_items(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();

        MockExam::factory()
            ->count(25)
            ->forCertification($certification)
            ->create();

        $this->actingAs($admin)
            ->get(route('admin.mock-exams.index'))
            ->assertOk()
            ->assertViewHas('mockExams', function ($mockExams): bool {
                return $mockExams->total() === 25
                    && $mockExams->perPage() === 20
                    && $mockExams->count() === 20;
            });

        $this->actingAs($admin)
            ->get(route('admin.mock-exams.index', ['page' => 2]))
            ->assertOk()
            ->assertViewHas('mockExams', function ($mockExams): bool {
                return $mockExams->total() === 25
                    && $mockExams->currentPage() === 2
                    && $mockExams->count() === 5;
            });
    }

    public function test_coach_can_only_view_mock_exams_for_assigned_certifications(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();

        $assignedCertification = Certification::factory()->published()->create();
        $unassignedCertification = Certification::factory()->published()->create();

        $assignedCertification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $assignedMockExam = MockExam::factory()
            ->forCertification($assignedCertification)
            ->create(['title' => '担当資格の模試']);

        $unassignedMockExam = MockExam::factory()
            ->forCertification($unassignedCertification)
            ->create(['title' => '未担当資格の模試']);

        $this->actingAs($coach)
            ->get(route('admin.mock-exams.index'))
            ->assertOk()
            ->assertViewHas('mockExams', function ($mockExams) use ($assignedMockExam, $unassignedMockExam): bool {
                $ids = $mockExams->getCollection()->pluck('id')->all();

                return $mockExams->total() === 1
                    && in_array($assignedMockExam->id, $ids, true)
                    && ! in_array($unassignedMockExam->id, $ids, true);
            });
    }

    public function test_student_cannot_access_mock_exam_management_index(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->get(route('admin.mock-exams.index'))
            ->assertForbidden();
    }
}
