<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;

/**
 * 個人学習目標(EnrollmentGoal)に対する認可ポリシー。
 *
 * 目標の作成・編集・削除・達成・未達成への変更は、
 * 対象 Enrollment の受講生本人のみ実行できる。
 * Coach / Admin は Enrollment 詳細画面から閲覧のみ可能。
 */
class EnrollmentGoalPolicy
{
    /**
     * 対象 Enrollment に目標を追加できるか判定する。
     */
    public function create(User $user, Enrollment $enrollment): bool
    {
        return $user->role === UserRole::Student
            && $enrollment->user_id === $user->id;
    }

    /**
     * 目標を編集できるか判定する。
     */
    public function update(User $user, EnrollmentGoal $goal): bool
    {
        return $this->isOwner($user, $goal);
    }

    /**
     * 目標を削除できるか判定する。
     */
    public function delete(User $user, EnrollmentGoal $goal): bool
    {
        return $this->isOwner($user, $goal);
    }

    /**
     * 目標を達成済みにできるか判定する。
     */
    public function markAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->isOwner($user, $goal);
    }

    /**
     * 目標を未達成に戻せるか判定する。
     */
    public function unmarkAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->isOwner($user, $goal);
    }

    /**
     * 対象目標が受講生本人の Enrollment に属しているか判定する。
     */
    private function isOwner(User $user, EnrollmentGoal $goal): bool
    {
        if ($user->role !== UserRole::Student) {
            return false;
        }

        $goal->loadMissing('enrollment');

        return $goal->enrollment?->user_id === $user->id;
    }
}
