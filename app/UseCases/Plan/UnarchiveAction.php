<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * アーカイブ済みの受講プランを下書きへ戻すユースケース。
 */
final class UnarchiveAction
{
    public function __invoke(Plan $plan, User $auth): Plan
    {
        if ($plan->status !== PlanStatus::Archived) {
            throw new DomainException('アーカイブ済みのプランのみ下書きへ戻せます。');
        }

        return DB::transaction(function () use ($plan, $auth): Plan {
            $plan->update([
                'status' => PlanStatus::Draft,
                'updated_by_user_id' => $auth->id,
            ]);

            return $plan->refresh();
        });
    }
}
