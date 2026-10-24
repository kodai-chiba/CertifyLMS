<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Enums\UserStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EnrollmentGoalController の HTTP 統合テスト。
 *
 * 受講生本人による CRUD・達成状態変更・バリデーションと、
 * 他受講生 / Coach / Admin による変更拒否を検証する。
 * あわせて Enrollment 削除時に紐づく個人学習目標が物理削除されることを検証する。
 */
class EnrollmentGoalControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_student_can_store_goal(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        // Act
        $response = $this->actingAs($student)->post(
            route('enrollments.goals.store', $enrollment),
            [
                'title' => '基本問題を一通り終える',
                'target_date' => '2026-12-01',
                'description' => '毎日少しずつ進める。',
            ],
        );

        // Assert
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success', '個人学習目標を登録しました。');

        $this->assertDatabaseHas('enrollment_goals', [
            'enrollment_id' => $enrollment->id,
            'title' => '基本問題を一通り終える',
            'target_date' => '2026-12-01',
            'description' => '毎日少しずつ進める。',
        ]);
    }

    public function test_store_accepts_nullable_target_date_and_description(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        // Act
        $response = $this->actingAs($student)->post(
            route('enrollments.goals.store', $enrollment),
            [
                'title' => '学習習慣をつける',
                'target_date' => null,
                'description' => null,
            ],
        );

        // Assert
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_goals', [
            'enrollment_id' => $enrollment->id,
            'title' => '学習習慣をつける',
            'target_date' => null,
            'description' => null,
        ]);
    }

    public function test_store_rejects_missing_title(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        // Act
        $response = $this->actingAs($student)
            ->from(route('enrollments.show', $enrollment))
            ->post(route('enrollments.goals.store', $enrollment), [
                'title' => '',
                'target_date' => '2026-12-01',
                'description' => '詳細',
            ]);

        // Assert
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHasErrors('title');
        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    public function test_store_rejects_title_longer_than_100_characters(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        // Act
        $response = $this->actingAs($student)->postJson(
            route('enrollments.goals.store', $enrollment),
            [
                'title' => str_repeat('あ', 101),
            ],
        );

        // Assert
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('title');
        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    public function test_store_rejects_description_longer_than_1000_characters(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        // Act
        $response = $this->actingAs($student)->postJson(
            route('enrollments.goals.store', $enrollment),
            [
                'title' => '学習目標',
                'description' => str_repeat('あ', 1001),
            ],
        );

        // Assert
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('description');
        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    public function test_store_rejects_invalid_target_date(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        // Act
        $response = $this->actingAs($student)->postJson(
            route('enrollments.goals.store', $enrollment),
            [
                'title' => '学習目標',
                'target_date' => 'invalid-date',
            ],
        );

        // Assert
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('target_date');
        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    public function test_other_student_cannot_store_goal_for_another_enrollment(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();

        // Act
        $response = $this->actingAs($other)->postJson(
            route('enrollments.goals.store', $enrollment),
            [
                'title' => '他人の目標',
            ],
        );

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    public function test_owner_student_can_open_edit_page(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        // Act
        $response = $this->actingAs($student)->get(
            route('enrollment-goals.edit', $goal),
        );

        // Assert
        $response->assertOk();
        $response->assertViewIs('enrollment-goal.edit');
        $response->assertViewHas('goal', function ($viewGoal) use ($goal) {
            return $viewGoal->is($goal);
        });
    }

    public function test_other_student_cannot_open_edit_page(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        // Act
        $response = $this->actingAs($other)->get(
            route('enrollment-goals.edit', $goal),
        );

        // Assert
        $response->assertForbidden();
    }

    public function test_owner_student_can_update_goal(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => '更新前',
            'description' => '更新前の詳細',
            'target_date' => '2026-11-01',
        ]);

        // Act
        $response = $this->actingAs($student)->patch(
            route('enrollment-goals.update', $goal),
            [
                'title' => '更新後',
                'description' => '更新後の詳細',
                'target_date' => '2026-12-20',
            ],
        );

        // Assert
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success', '個人学習目標を更新しました。');

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'title' => '更新後',
            'description' => '更新後の詳細',
            'target_date' => '2026-12-20',
        ]);
    }

    public function test_update_validates_input(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => '更新前',
        ]);

        // Act
        $response = $this->actingAs($student)->patchJson(
            route('enrollment-goals.update', $goal),
            [
                'title' => str_repeat('あ', 101),
                'description' => str_repeat('あ', 1001),
                'target_date' => 'invalid-date',
            ],
        );

        // Assert
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors([
            'title',
            'description',
            'target_date',
        ]);

        $this->assertSame('更新前', $goal->fresh()->title);
    }

    public function test_other_student_cannot_update_goal(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => '更新前',
        ]);

        // Act
        $response = $this->actingAs($other)->patchJson(
            route('enrollment-goals.update', $goal),
            [
                'title' => '不正な更新',
            ],
        );

        // Assert
        $response->assertForbidden();
        $this->assertSame('更新前', $goal->fresh()->title);
    }

    public function test_owner_student_can_delete_goal_physically(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        // Act
        $response = $this->actingAs($student)->delete(
            route('enrollment-goals.destroy', $goal),
        );

        // Assert
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success', '個人学習目標を削除しました。');

        $this->assertDatabaseMissing('enrollment_goals', [
            'id' => $goal->id,
        ]);
    }

    public function test_other_student_cannot_delete_goal(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        // Act
        $response = $this->actingAs($other)->deleteJson(
            route('enrollment-goals.destroy', $goal),
        );

        // Assert
        $response->assertForbidden();

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
        ]);
    }

    public function test_owner_student_can_mark_goal_as_achieved(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->unachieved()
            ->create();

        // Act
        $response = $this->actingAs($student)->post(
            route('enrollment-goals.markAchieved', $goal),
        );

        // Assert
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success', '個人学習目標を達成済みにしました。');
        $this->assertNotNull($goal->fresh()->achieved_at);
    }

    public function test_other_student_cannot_mark_goal_as_achieved(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->unachieved()
            ->create();

        // Act
        $response = $this->actingAs($other)->postJson(
            route('enrollment-goals.markAchieved', $goal),
        );

        // Assert
        $response->assertForbidden();
        $this->assertNull($goal->fresh()->achieved_at);
    }

    public function test_owner_student_can_unmark_achieved_goal(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->achieved()
            ->create();

        // Act
        $response = $this->actingAs($student)->delete(
            route('enrollment-goals.unmarkAchieved', $goal),
        );

        // Assert
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success', '個人学習目標を未達成に戻しました。');
        $this->assertNull($goal->fresh()->achieved_at);
    }

    public function test_other_student_cannot_unmark_achieved_goal(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->achieved()
            ->create();

        // Act
        $response = $this->actingAs($other)->deleteJson(
            route('enrollment-goals.unmarkAchieved', $goal),
        );

        // Assert
        $response->assertForbidden();
        $this->assertNotNull($goal->fresh()->achieved_at);
    }

    public function test_coach_cannot_use_goal_mutation_routes(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        // Act & Assert
        $this->actingAs($coach)
            ->postJson(route('enrollments.goals.store', $enrollment), [
                'title' => 'Coachによる登録',
            ])
            ->assertForbidden();

        $this->actingAs($coach)
            ->patchJson(route('enrollment-goals.update', $goal), [
                'title' => 'Coachによる更新',
            ])
            ->assertForbidden();

        $this->actingAs($coach)
            ->deleteJson(route('enrollment-goals.destroy', $goal))
            ->assertForbidden();

        $this->actingAs($coach)
            ->postJson(route('enrollment-goals.markAchieved', $goal))
            ->assertForbidden();

        $this->actingAs($coach)
            ->deleteJson(route('enrollment-goals.unmarkAchieved', $goal))
            ->assertForbidden();
    }

    public function test_admin_cannot_use_goal_mutation_routes(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $admin = User::factory()->admin()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        // Act & Assert
        $this->actingAs($admin)
            ->postJson(route('enrollments.goals.store', $enrollment), [
                'title' => 'Adminによる登録',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patchJson(route('enrollment-goals.update', $goal), [
                'title' => 'Adminによる更新',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->deleteJson(route('enrollment-goals.destroy', $goal))
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson(route('enrollment-goals.markAchieved', $goal))
            ->assertForbidden();

        $this->actingAs($admin)
            ->deleteJson(route('enrollment-goals.unmarkAchieved', $goal))
            ->assertForbidden();
    }

    public function test_deleting_enrollment_physically_deletes_its_goals(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $firstGoal = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->create();

        $secondGoal = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->achieved()
            ->create();

        // Act
        $response = $this->actingAs($student)->delete(
            route('enrollments.destroy', $enrollment),
        );

        // Assert
        $response->assertRedirect(route('enrollments.index'));
        $this->assertSoftDeleted('enrollments', [
            'id' => $enrollment->id,
        ]);

        $this->assertDatabaseMissing('enrollment_goals', [
            'id' => $firstGoal->id,
        ]);

        $this->assertDatabaseMissing('enrollment_goals', [
            'id' => $secondGoal->id,
        ]);
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        // Arrange
        $enrollment = Enrollment::factory()->learning()->create();

        // Act
        $response = $this->post(
            route('enrollments.goals.store', $enrollment),
            [
                'title' => '未ログインユーザーの目標',
            ],
        );

        // Assert
        $response->assertRedirect(route('login'));
        $this->assertDatabaseCount('enrollment_goals', 0);
    }
    /**
     * 修了済み・退会済みの受講生は個人学習目標を登録できない。
     */
    public function test_graduated_and_withdrawn_students_cannot_store_goals(): void
    {
        foreach ([UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
            // Arrange
            $student = User::factory()->student()->inProgress()->create();
            $enrollment = Enrollment::factory()->for($student)->learning()->create();
            $student->update(['status' => $status]);

            // Act & Assert
            $this->actingAs($student)
                ->postJson(route('enrollments.goals.store', $enrollment), [
                    'title' => '登録できない目標',
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    /**
     * 修了済み・退会済みの受講生は既存目標の操作にもアクセスできない。
     */
    public function test_graduated_and_withdrawn_students_cannot_access_goal_routes(): void
    {
        foreach ([UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
            // Arrange
            $student = User::factory()->student()->inProgress()->create();
            $enrollment = Enrollment::factory()->for($student)->learning()->create();
            $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->unachieved()->create();
            $student->update(['status' => $status]);

            // Act & Assert
            $this->actingAs($student)->get(route('enrollment-goals.edit', $goal))->assertForbidden();
            $this->actingAs($student)->patchJson(route('enrollment-goals.update', $goal), [
                'title' => '変更不可',
            ])->assertForbidden();
            $this->actingAs($student)->deleteJson(route('enrollment-goals.destroy', $goal))->assertForbidden();
            $this->actingAs($student)->postJson(route('enrollment-goals.markAchieved', $goal))->assertForbidden();
            $this->actingAs($student)->deleteJson(route('enrollment-goals.unmarkAchieved', $goal))->assertForbidden();

            $this->assertDatabaseHas('enrollment_goals', [
                'id' => $goal->id,
                'title' => $goal->title,
                'achieved_at' => null,
            ]);
        }
    }

    /**
     * 達成済みの目標は編集画面・更新・削除を禁止する。
     */
    public function test_achieved_goal_cannot_be_edited_updated_or_deleted(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create([
            'title' => '達成済みの目標',
        ]);

        // Act & Assert
        $this->actingAs($student)->get(route('enrollment-goals.edit', $goal))->assertForbidden();
        $this->actingAs($student)->patchJson(route('enrollment-goals.update', $goal), [
            'title' => '不正な更新',
        ])->assertForbidden();
        $this->actingAs($student)->deleteJson(route('enrollment-goals.destroy', $goal))->assertForbidden();

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'title' => '達成済みの目標',
        ]);
    }

    /**
     * 目標期日に過去日付を指定して登録できる。
     */
    public function test_store_accepts_past_target_date(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $pastDate = now()->subYear()->toDateString();

        // Act
        $response = $this->actingAs($student)->post(route('enrollments.goals.store', $enrollment), [
            'title' => '過去の日付の目標',
            'target_date' => $pastDate,
        ]);

        // Assert
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertDatabaseHas('enrollment_goals', [
            'enrollment_id' => $enrollment->id,
            'title' => '過去の日付の目標',
            'target_date' => $pastDate,
        ]);
    }

    /**
     * 同じ受講登録に複数の目標を追加できる。
     */
    public function test_owner_student_can_store_multiple_goals_for_one_enrollment(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        // Act & Assert
        foreach (range(1, 5) as $number) {
            $this->actingAs($student)->post(route('enrollments.goals.store', $enrollment), [
                'title' => '学習目標'.$number,
            ])->assertRedirect(route('enrollments.show', $enrollment));
        }

        $this->assertSame(5, $enrollment->goals()->count());
    }

}
