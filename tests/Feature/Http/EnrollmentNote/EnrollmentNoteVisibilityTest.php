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
 * S-B-07 受講生メモの閲覧権限に関するHTTP統合テスト。
 *
 * - student: メモ一覧を閲覧できない
 * - 担当coach: 他の担当コーチが作成したメモも閲覧できる
 * - 担当外coach: メモ編集画面にアクセスできない
 * - admin: 他人のメモも削除できる
 * - 削除済み受講登録: メモの操作を禁止する
 */
class EnrollmentNoteVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 資格に担当コーチを割り当てる。
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
     * 指定した受講登録にメモを作成する。
     */
    private function createNote(
        Enrollment $enrollment,
        User $author,
        string $body,
    ): EnrollmentNote {
        return EnrollmentNote::create([
            'enrollment_id' => $enrollment->id,
            'author_id' => $author->id,
            'body' => $body,
        ]);
    }

    /**
     * 受講生本人の詳細画面にメモ本文が表示されない。
     */
    public function test_student_cannot_see_note_on_enrollment_page(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();

        $enrollment = Enrollment::factory()->for($student)->create();

        $this->createNote(
            $enrollment,
            $coach,
            '受講生には表示してはいけないメモ'
        );

        $response = $this->actingAs($student)->get(
            route('enrollments.show', $enrollment)
        );

        $response->assertOk();
        $response->assertDontSee('受講生には表示してはいけないメモ');
    }

    /**
     * 担当コーチは他の担当コーチが作成したメモを閲覧できる。
     */
    public function test_assigned_coach_can_see_other_coach_note(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();

        $enrollment = Enrollment::factory()->create();

        $this->assignCoach($enrollment->certification, $coach, $admin);
        $this->assignCoach($enrollment->certification, $otherCoach, $admin);

        $this->createNote(
            $enrollment,
            $otherCoach,
            '担当コーチ間で共有するメモ'
        );

        $response = $this->actingAs($coach)->get(
            route('enrollments.show', $enrollment)
        );

        $response->assertOk();
        $response->assertSee('担当コーチ間で共有するメモ');
    }

    /**
     * 担当外コーチはメモ編集画面を開けない。
     */
    public function test_unassigned_coach_cannot_open_note_edit_page(): void
    {
        $coach = User::factory()->coach()->create();
        $author = User::factory()->coach()->create();

        $enrollment = Enrollment::factory()->create();

        $note = $this->createNote(
            $enrollment,
            $author,
            '担当外コーチには編集できないメモ'
        );

        $response = $this->actingAs($coach)->get(
            route('enrollment-notes.edit', $note)
        );

        $response->assertForbidden();
    }

    /**
     * 管理者は他人が作成したメモも削除できる。
     */
    public function test_admin_can_delete_other_user_note(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();

        $enrollment = Enrollment::factory()->create();

        $note = $this->createNote(
            $enrollment,
            $coach,
            '管理者が削除するメモ'
        );

        $response = $this->actingAs($admin)->delete(
            route('enrollment-notes.destroy', $note)
        );

        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseMissing('enrollment_notes', [
            'id' => $note->id,
        ]);
    }

    /**
     * 削除済み受講登録には新しいメモを追加できない。
     */
    public function test_admin_cannot_store_note_on_deleted_enrollment(): void
    {
        $admin = User::factory()->admin()->create();

        $enrollment = Enrollment::factory()->create();
        $enrollment->delete();

        $response = $this->actingAs($admin)->postJson(
            route('enrollments.notes.store', $enrollment),
            ['body' => '削除済み受講登録へのメモ']
        );

        // 通常のルートモデルバインディングでは削除済みの受講登録は404になる。
        // withTrashedが適用される構成でもPolicyで403となることを想定する。
        $this->assertContains($response->status(), [403, 404]);

        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    /**
     * 削除済み受講登録のメモは編集できない。
     */
    public function test_admin_cannot_update_note_on_deleted_enrollment(): void
    {
        $admin = User::factory()->admin()->create();

        $enrollment = Enrollment::factory()->create();

        $note = $this->createNote(
            $enrollment,
            $admin,
            '削除前のメモ'
        );

        $enrollment->delete();

        $response = $this->actingAs($admin)->patchJson(
            route('enrollment-notes.update', $note),
            ['body' => '削除後の不正な更新']
        );

        $this->assertContains($response->status(), [403, 404]);

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => '削除前のメモ',
        ]);
    }
}
