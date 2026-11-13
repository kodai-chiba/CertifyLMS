<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Certificate;
use App\Models\User;

class CertificatePolicy
{
    /**
     * 修了証PDFのダウンロード権限を判定する。
     *
     * - Admin：全件許可
     * - Student：本人の修了証のみ許可
     * - Coach：担当資格の修了証のみ許可
     */
    public function download(User $user, Certificate $certificate): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Student => $certificate->user_id === $user->id,
            UserRole::Coach => $this->isAssignedCoach($certificate, $user),
        };
    }

    /**
     * 担当コーチかどうかを判定する。
     */
    private function isAssignedCoach(Certificate $certificate, User $coach): bool
    {
        $certificate->loadMissing('certification.coaches');

        return $certificate->certification?->coaches->contains('id', $coach->id) ?? false;
    }
}
