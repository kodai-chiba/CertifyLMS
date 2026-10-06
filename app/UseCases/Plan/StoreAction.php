<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 受講プランマスタを下書きとして新規作成するユースケース。
 */
final class StoreAction
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
    public function __invoke(array $validated, User $auth): Plan
    {
        return DB::transaction(fn () => Plan::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'duration_days' => $validated['duration_days'],
            'default_meeting_quota' => $validated['default_meeting_quota'],
            'status' => PlanStatus::Draft,
            'sort_order' => $validated['sort_order'] ?? 0,
            'created_by_user_id' => $auth->id,
            'updated_by_user_id' => $auth->id,
        ]));
    }
}
