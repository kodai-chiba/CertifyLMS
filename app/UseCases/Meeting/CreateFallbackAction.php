<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\EnrollmentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * 面談予約画面の代替表示に必要な受講登録一覧を取得する。
 */
final class CreateFallbackAction
{
    public function __invoke(?User $user): Collection
    {
        if ($user === null) {
            return new Collection;
        }

        return $user->enrollments()
            ->whereIn('status', [
                EnrollmentStatus::Learning->value,
                EnrollmentStatus::Passed->value,
            ])
            ->with('certification')
            ->get();
    }
}
