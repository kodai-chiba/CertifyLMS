<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * 面談予約 (Meeting) リソースに対する認可ポリシー。
 *
 * - viewAny: 全ロール true(取得スコープは IndexAction 側で coach_id / student_id 単位に絞る)
 * - view: 管理者 / 担当コーチ / 受講中の当事者である受講生のみ
 * - create: 受講中の受講生のみ(コーチは自動割当の被選出側、admin は介入なし)
 * - cancel: 当事者かつ reserved 状態の Meeting のみ(受講生は受講中の場合のみ)
 * - upsertMemo: 担当コーチかつ reserved / completed 状態(canceled の Meeting にはメモを残せない)
 */
class MeetingPolicy
{
    private const PLAN_EXPIRED_MESSAGE = 'プラン期間が満了しました。プラン機能はご利用いただけません。プロフィール / 修了証は引き続きアクセス可能です。';

    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Coach, UserRole::Student], true);
    }

    public function view(User $user, Meeting $meeting): Response
    {
        if ($user->role === UserRole::Student
            && $meeting->student_id === $user->id
            && $user->status !== UserStatus::InProgress) {
            return Response::deny(self::PLAN_EXPIRED_MESSAGE);
        }

        $allowed = match ($user->role) {
            UserRole::Admin => true,
            UserRole::Coach => $meeting->coach_id === $user->id,
            UserRole::Student => $meeting->student_id === $user->id,
        };

        return $allowed ? Response::allow() : Response::deny();
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress;
    }

    public function cancel(User $user, Meeting $meeting): Response
    {
        if ($meeting->status !== MeetingStatus::Reserved) {
            return Response::deny();
        }

        if ($user->role === UserRole::Student
            && $meeting->student_id === $user->id
            && $user->status !== UserStatus::InProgress) {
            return Response::deny(self::PLAN_EXPIRED_MESSAGE);
        }

        $allowed = $meeting->student_id === $user->id
            || $meeting->coach_id === $user->id;

        return $allowed ? Response::allow() : Response::deny();
    }

    public function upsertMemo(User $user, Meeting $meeting): bool
    {
        if ($user->role !== UserRole::Coach || $meeting->coach_id !== $user->id) {
            return false;
        }

        return in_array($meeting->status, [MeetingStatus::Reserved, MeetingStatus::Completed], true);
    }
}
