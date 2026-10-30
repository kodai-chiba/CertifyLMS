<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\User;

/**
 * 受講プランマスタの認可ルール。管理者のみ操作可能。
 */
class PlanPolicy
{
    public function viewAny(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function view(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function create(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function update(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function delete(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin
            && $plan->status === PlanStatus::Draft
            && ! $plan->users()->exists()
            && ! $plan->userPlanLogs()->exists();
    }

    public function publish(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin
            && $plan->status === PlanStatus::Draft;
    }

    public function archive(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin
            && $plan->status === PlanStatus::Published;
    }

    public function unarchive(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin
            && $plan->status === PlanStatus::Archived;
    }
}
