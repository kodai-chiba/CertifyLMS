<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;

class QaReplyPolicy
{
    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, QaThread $qaThread): bool
    {
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
     * Determine whether the user can update the model.
     */
    public function update(User $user, QaReply $qaReply): bool
    {
        if (! in_array($user->role, [
            UserRole::Student,
            UserRole::Coach,
        ], true) || $user->id !== $qaReply->user_id) {
            return false;
        }

        $qaReply->loadMissing('thread.certification');

        if ($qaReply->thread->certification->status !== CertificationStatus::Published) {
            return false;
        }

        return match ($user->role) {
            UserRole::Student => true,
            UserRole::Coach => $this->assignedCoach(
                $user,
                $qaReply->thread->certification,
            ),
            default => false,
        };
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, QaReply $qaReply): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        return $this->update($user, $qaReply);
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
