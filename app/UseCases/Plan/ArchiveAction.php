<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * 公開中の受講プランをアーカイブするユースケース。
 */
final class ArchiveAction
{
    public function __invoke(Plan $plan, User $auth): Plan
    {
        if ($plan->status !== PlanStatus::Published) {
            throw new DomainException('公開中のプランのみアーカイブできます。');
        }

        return DB::transaction(function () use ($plan, $auth): Plan {
            $plan->update([
                'status' => PlanStatus::Archived,
                'updated_by_user_id' => $auth->id,
            ]);

            return $plan->refresh();
        });
    }
}
