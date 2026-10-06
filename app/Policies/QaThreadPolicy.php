<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;

class QaThreadPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::Student,
            UserRole::Coach,
            UserRole::Admin,
        ], true);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, QaThread $qaThread): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        $qaThread->loadMissing('certification');

        if ($qaThread->certification->status !== CertificationStatus::Published) {
            return false;
        }

        return match ($user->role) {
            UserRole::Student => true,
            UserRole::Coach => $this->assignedCoach($user, $qaThread->certification),
            default => false,
        };
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, QaThread $qaThread): bool
    {
        return $this->view($user, $qaThread)
            && $user->role === UserRole::Student
            && $user->id === $qaThread->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, QaThread $qaThread): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        return $this->view($user, $qaThread)
            && $user->role === UserRole::Student
            && $user->id === $qaThread->user_id;
    }

    /**
     * Determine whether the user can resolve the model.
     */
    public function resolve(User $user, QaThread $qaThread): bool
    {
        return $this->view($user, $qaThread)
            && $user->role === UserRole::Student
            && $user->id === $qaThread->user_id
            && $qaThread->status === QaThreadStatus::Unresolved;
    }

    /**
     * Determine whether the user can unresolve the model.
     */
    public function unresolve(User $user, QaThread $qaThread): bool
    {
        return $this->view($user, $qaThread)
            && $user->role === UserRole::Student
            && $user->id === $qaThread->user_id
            && $qaThread->status === QaThreadStatus::Resolved;
    }

    /**
     * Determine whether the coach is assigned to the certification.
     */
    private function assignedCoach(User $coach, Certification $certification): bool
    {
        return $certification->coaches()
            ->where('users.id', $coach->id)
            ->exists();
    }
}
