<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExam;

use App\Models\Certification;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_mock_exam_as_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();

        $this->actingAs($admin)
            ->post(route('admin.mock-exams.store'), [
                'certification_id' => $cert->id,
                'title' => '第 1 回 本番形式',
                'description' => '本番試験を想定した模試',
                'order' => 0,
                'passing_score' => 60,
            ])
            ->assertRedirect();

        $mockExam = MockExam::firstOrFail();
        $this->assertFalse($mockExam->is_published);
        $this->assertNull($mockExam->published_at);
        $this->assertSame($admin->id, $mockExam->created_by_user_id);
        $this->assertSame($admin->id, $mockExam->updated_by_user_id);
    }

    public function test_store_silently_drops_time_limit_minutes_field(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();

        // E-3: time_limit_minutes は受け取らない(silently drop)
        $this->actingAs($admin)
            ->post(route('admin.mock-exams.store'), [
                'certification_id' => $cert->id,
                'title' => '第 1 回',
                'description' => null,
                'order' => 0,
                'passing_score' => 60,
                'time_limit_minutes' => 90,
            ])
            ->assertRedirect();

        $mockExam = MockExam::firstOrFail();
        $this->assertArrayNotHasKey('time_limit_minutes', $mockExam->getAttributes());
    }

    public function test_coach_cannot_create_mock_exam_for_unassigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $otherCert = Certification::factory()->published()->create();

        $this->actingAs($coach)
            ->postJson(route('admin.mock-exams.store'), [
                'certification_id' => $otherCert->id,
                'title' => 'unauthorized',
                'description' => null,
                'order' => 0,
                'passing_score' => 60,
            ])
            ->assertForbidden();
    }

    public function test_student_cannot_access_admin_mock_exams_index(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->get(route('admin.mock-exams.index'))
            ->assertForbidden();
    }

    public function test_admin_can_update_mock_exam_without_changing_certification(): void
    {
        $admin = User::factory()->admin()->create();
        $mockExam = MockExam::factory()->create();
        $originalCertId = $mockExam->certification_id;

        $this->actingAs($admin)
            ->put(route('admin.mock-exams.update', $mockExam), [
                'title' => '改題後',
                'description' => '更新された説明',
                'order' => 5,
                'passing_score' => 80,
            ])
            ->assertRedirect();

        $mockExam->refresh();
        $this->assertSame('改題後', $mockExam->title);
        $this->assertSame(80, $mockExam->passing_score);
        $this->assertSame($originalCertId, $mockExam->certification_id);
    }

    public function test_store_accepts_passing_score_boundary_values(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();

        foreach ([1, 100] as $passingScore) {
            $this->actingAs($admin)
                ->postJson(route('admin.mock-exams.store'), [
                    'certification_id' => $cert->id,
                    'title' => "合格点{$passingScore}%の模試",
                    'description' => null,
                    'order' => 0,
                    'passing_score' => $passingScore,
                ])
                ->assertRedirect();

            $this->assertDatabaseHas('mock_exams', [
                'title' => "合格点{$passingScore}%の模試",
                'passing_score' => $passingScore,
            ]);
        }
    }

    public function test_store_rejects_passing_score_outside_valid_range(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();

        foreach ([0, 101, 150] as $passingScore) {
            $this->actingAs($admin)
                ->postJson(route('admin.mock-exams.store'), [
                    'certification_id' => $cert->id,
                    'title' => "不正な合格点{$passingScore}%の模試",
                    'description' => null,
                    'order' => 0,
                    'passing_score' => $passingScore,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['passing_score']);

            $this->assertDatabaseMissing('mock_exams', [
                'title' => "不正な合格点{$passingScore}%の模試",
            ]);
        }
    }

    public function test_update_accepts_passing_score_boundary_values(): void
    {
        $admin = User::factory()->admin()->create();
        $mockExam = MockExam::factory()->create();

        foreach ([1, 100] as $passingScore) {
            $this->actingAs($admin)
                ->putJson(route('admin.mock-exams.update', $mockExam), [
                    'title' => '境界値テスト',
                    'description' => null,
                    'order' => 0,
                    'passing_score' => $passingScore,
                ])
                ->assertRedirect();

            $mockExam->refresh();
            $this->assertSame($passingScore, $mockExam->passing_score);
        }
    }

    public function test_update_rejects_passing_score_outside_valid_range(): void
    {
        $admin = User::factory()->admin()->create();
        $mockExam = MockExam::factory()->create([
            'title' => '更新前の模試',
            'passing_score' => 60,
        ]);

        foreach ([0, 101, 150] as $passingScore) {
            $this->actingAs($admin)
                ->putJson(route('admin.mock-exams.update', $mockExam), [
                    'title' => '不正な更新',
                    'description' => null,
                    'order' => 0,
                    'passing_score' => $passingScore,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['passing_score']);

            $mockExam->refresh();
            $this->assertSame('更新前の模試', $mockExam->title);
            $this->assertSame(60, $mockExam->passing_score);
        }
    }

    public function test_destroy_rejects_published_mock_exam(): void
    {
        $admin = User::factory()->admin()->create();
        $mockExam = MockExam::factory()->published()->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.mock-exams.destroy', $mockExam))
            ->assertStatus(409);

        $this->assertDatabaseHas('mock_exams', ['id' => $mockExam->id]);
    }

    public function test_destroy_rejects_when_active_session_exists(): void
    {
        $admin = User::factory()->admin()->create();
        $mockExam = MockExam::factory()->create();
        MockExamSession::factory()->forMockExam($mockExam)->inProgress()->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.mock-exams.destroy', $mockExam))
            ->assertStatus(409);

        $this->assertDatabaseHas('mock_exams', ['id' => $mockExam->id]);
    }

    public function test_destroy_succeeds_when_unpublished_and_no_session(): void
    {
        $admin = User::factory()->admin()->create();
        $mockExam = MockExam::factory()->create();

        $this->actingAs($admin)
            ->delete(route('admin.mock-exams.destroy', $mockExam))
            ->assertRedirect();

        $this->assertDatabaseMissing('mock_exams', ['id' => $mockExam->id]);
    }
}
