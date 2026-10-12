<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;

/**
 * 受講生メモの認可ポリシー。
 *
 * - admin: すべての受講登録のメモを閲覧・作成・編集・削除可能
 * - coach: 担当資格の受講登録に紐づくメモを閲覧・作成可能
 * - coach: 編集・削除できるのは自分が作成したメモのみ
 * - student: すべての操作を禁止
 *
 * 担当コーチは Enrollment に直接紐づかず、
 * Certification の coaches リレーションを通じて判定する。
 */
class EnrollmentNotePolicy
{
    /**
     * 指定された受講登録のメモ一覧を閲覧できるか。
     *
     * Bladeでは @can('viewAny', [EnrollmentNote::class, $enrollment])
     * として呼び出される。
     */
    public function viewAny(User $user, Enrollment $enrollment): bool
    {
        return $this->canAccessEnrollment($user, $enrollment);
    }

    /**
     * 指定された受講登録のメモを閲覧できるか。
     */
    public function view(User $user, EnrollmentNote $note): bool
    {
        $enrollment = $note->enrollment;

        if ($enrollment === null) {
            return false;
        }

        return $this->canAccessEnrollment($user, $enrollment);
    }

    /**
     * 指定された受講登録にメモを作成できるか。
     *
     * Bladeでは @can('create', [EnrollmentNote::class, $enrollment])
     * として呼び出される。
     */
    public function create(User $user, Enrollment $enrollment): bool
    {
        return $this->canAccessEnrollment($user, $enrollment);
    }

    /**
     * メモを編集できるか。
     */
    public function update(User $user, EnrollmentNote $note): bool
    {
        if (! $this->view($user, $note)) {
            return false;
        }

        return $user->role === UserRole::Admin
            || $note->author_id === $user->id;
    }

    /**
     * メモを削除できるか。
     */
    public function delete(User $user, EnrollmentNote $note): bool
    {
        return $this->update($user, $note);
    }

    /**
     * 対象の受講登録にアクセス可能かを判定する。
     */
    private function canAccessEnrollment(User $user, Enrollment $enrollment): bool
    {
        if ($enrollment->trashed()) {
            return false;
        }

        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Coach => $this->isAssignedCoach($enrollment, $user),
            default => false,
        };
    }

    /**
     * 対象資格の担当コーチかどうかを判定する。
     */
    private function isAssignedCoach(Enrollment $enrollment, User $coach): bool
    {
        $enrollment->loadMissing('certification.coaches');

        return $enrollment->certification?->coaches->contains('id', $coach->id) ?? false;
    }
}
