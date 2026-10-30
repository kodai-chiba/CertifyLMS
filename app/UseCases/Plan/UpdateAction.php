<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 受講プランマスタの基本情報を更新するユースケース。
 */
final class UpdateAction
{
    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     duration_days: int,
     *     default_meeting_quota: int,
     *     sort_order?: int|null
     * }  $validated
     */
    public function __invoke(Plan $plan, array $validated, User $auth): Plan
    {
        return DB::transaction(function () use ($plan, $validated, $auth): Plan {
            $plan->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'duration_days' => $validated['duration_days'],
                'default_meeting_quota' => $validated['default_meeting_quota'],
                'sort_order' => $validated['sort_order'] ?? 0,
                'updated_by_user_id' => $auth->id,
            ]);

            return $plan->refresh();
        });
    }
}
