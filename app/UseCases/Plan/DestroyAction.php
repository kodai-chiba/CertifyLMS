<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * 受講プランマスタを物理削除するユースケース。
 */
final class DestroyAction
{
    /**
     * @throws DomainException 削除条件を満たさない
     */
    public function __invoke(Plan $plan): void
    {
        if ($plan->status !== PlanStatus::Draft) {
            throw new DomainException('下書きのプランのみ削除できます。');
        }

        if ($plan->users()->exists()) {
            throw new DomainException('受講者が紐づいているプランは削除できません。');
        }

        if ($plan->userPlanLogs()->exists()) {
            throw new DomainException('利用履歴が存在するプランは削除できません。');
        }

        DB::transaction(fn () => $plan->delete());
    }
}
