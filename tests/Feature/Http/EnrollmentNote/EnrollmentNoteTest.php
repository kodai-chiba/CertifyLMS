<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentNote;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S-B-07 受講生メモ管理のHTTP統合テスト。
 *
 * - admin: 全件操作可能
 * - 担当coach: 閲覧・作成可能、編集・削除は本人のメモのみ
 * - 担当外coach: 操作不可
 * - student: 閲覧・操作不可
 * - 本文の入力検証
 */
class EnrollmentNoteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 担当コーチを資格に割り当てる。
     */
    private function assignCoach(
        Certification $certification,
        User $coach,
        User $admin,
    ): void {
        CertificationCoachAssignment::create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
    }

    /**
     * メモを作成する。
     */
    private function createNote(
        Enrollment $enrollment,
        User $author,
        string $body = 'テスト用メモ',
    ): EnrollmentNote {
        return EnrollmentNote::create([
            'enrollment_id' => $enrollment->id,
            'author_id' => $author->id,
            'body' => $body,
        ]);
    }

    /**
     * 管理者はメモを登録できる。
     */
    public function test_admin_can_store_note(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->create();

        $response = $this->actingAs($admin)->post(
            route('enrollments.notes.store', $enrollment),
            ['body' => '管理者が登録したメモ']
        );

        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'author_id' => $admin->id,
            'body' => '管理者が登録したメモ',
        ]);
    }

    /**
     * 担当コーチはメモを登録できる。
     */
    public function test_assigned_coach_can_store_note(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->create();

        $this->assignCoach($enrollment->certification, $coach, $admin);

        $response = $this->actingAs($coach)->post(
            route('enrollments.notes.store', $enrollment),
            ['body' => '担当コーチのメモ']
        );

        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'author_id' => $coach->id,
            'body' => '担当コーチのメモ',
        ]);
    }

    /**
     * 担当外コーチはメモを登録できない。
     */
    public function test_unassigned_coach_cannot_store_note(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->create();

        $response = $this->actingAs($coach)->postJson(
            route('enrollments.notes.store', $enrollment),
            ['body' => '登録できないメモ']
        );

        $response->assertForbidden();

        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    /**
     * 受講生は自分の受講登録にもメモを登録できない。
     */
    public function test_student_cannot_store_note(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->create();

        $response = $this->actingAs($student)->postJson(
            route('enrollments.notes.store', $enrollment),
            ['body' => '受講生のメモ']
        );

        $response->assertForbidden();

        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    /**
     * 担当コーチは自分のメモを編集できる。
     */
    public function test_assigned_coach_can_update_own_note(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->create();

        $this->assignCoach($enrollment->certification, $coach, $admin);

        $note = $this->createNote($enrollment, $coach);

        $response = $this->actingAs($coach)->patch(
            route('enrollment-notes.update', $note),
            ['body' => '更新後のメモ']
        );

        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => '更新後のメモ',
        ]);
    }

    /**
     * 担当コーチでも他人のメモは編集できない。
     */
    public function test_assigned_coach_cannot_update_other_coach_note(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->create();

        $this->assignCoach($enrollment->certification, $coach, $admin);
        $this->assignCoach($enrollment->certification, $otherCoach, $admin);

        $note = $this->createNote($enrollment, $otherCoach);

        $response = $this->actingAs($coach)->patchJson(
            route('enrollment-notes.update', $note),
            ['body' => '不正な更新']
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => 'テスト用メモ',
        ]);
    }

    /**
     * 管理者は他人のメモも編集できる。
     */
    public function test_admin_can_update_other_user_note(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->create();

        $note = $this->createNote($enrollment, $coach);

        $response = $this->actingAs($admin)->patch(
            route('enrollment-notes.update', $note),
            ['body' => '管理者による更新']
        );

        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => '管理者による更新',
        ]);
    }

    /**
     * 担当コーチは自分のメモを削除できる。
     */
    public function test_assigned_coach_can_delete_own_note(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->create();

        $this->assignCoach($enrollment->certification, $coach, $admin);

        $note = $this->createNote($enrollment, $coach);

        $response = $this->actingAs($coach)->delete(
            route('enrollment-notes.destroy', $note)
        );

        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseMissing('enrollment_notes', [
            'id' => $note->id,
        ]);
    }

    /**
     * 担当コーチでも他人のメモは削除できない。
     */
    public function test_assigned_coach_cannot_delete_other_coach_note(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->create();

        $this->assignCoach($enrollment->certification, $coach, $admin);
        $this->assignCoach($enrollment->certification, $otherCoach, $admin);

        $note = $this->createNote($enrollment, $otherCoach);

        $response = $this->actingAs($coach)->deleteJson(
            route('enrollment-notes.destroy', $note)
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
        ]);
    }

    /**
     * 受講生はメモ編集画面を開けない。
     */
    public function test_student_cannot_open_note_edit_page(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->for($student)->create();

        $note = $this->createNote($enrollment, $coach);

        $response = $this->actingAs($student)->get(
            route('enrollment-notes.edit', $note)
        );

        $response->assertForbidden();
    }

    /**
     * メモ本文が空欄の場合は登録できない。
     */
    public function test_store_rejects_empty_body(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->create();

        $response = $this->actingAs($admin)->postJson(
            route('enrollments.notes.store', $enrollment),
            ['body' => '']
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('body');
    }

    /**
     * メモ本文が空白のみの場合は登録できない。
     */
    public function test_store_rejects_whitespace_only_body(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->create();

        $response = $this->actingAs($admin)->postJson(
            route('enrollments.notes.store', $enrollment),
            ['body' => '   ']
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('body');
    }

    /**
     * メモ本文が2000文字を超える場合は登録できない。
     */
    public function test_store_rejects_body_over_2000_characters(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->create();

        $response = $this->actingAs($admin)->postJson(
            route('enrollments.notes.store', $enrollment),
            ['body' => str_repeat('あ', 2001)]
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('body');
    }

    /**
     * 未認証ユーザーはメモを登録できない。
     */
    public function test_guest_cannot_store_note(): void
    {
        $enrollment = Enrollment::factory()->create();

        $response = $this->post(
            route('enrollments.notes.store', $enrollment),
            ['body' => '未認証ユーザーのメモ']
        );

        $response->assertRedirect(route('login'));

        $this->assertDatabaseCount('enrollment_notes', 0);
    }
}
