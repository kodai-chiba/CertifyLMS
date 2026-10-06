<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * 下書きの受講プランを公開するユースケース。
 */
final class PublishAction
{
    public function __invoke(Plan $plan, User $auth): Plan
    {
        if ($plan->status !== PlanStatus::Draft) {
            throw new DomainException('下書きのプランのみ公開できます。');
        }

        return DB::transaction(function () use ($plan, $auth): Plan {
            $plan->update([
                'status' => PlanStatus::Published,
                'updated_by_user_id' => $auth->id,
            ]);

            return $plan->refresh();
        });
    }
}
