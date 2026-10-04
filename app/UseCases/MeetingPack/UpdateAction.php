<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateAction
{
    /**
     * @param array{
     *     name: string,
     *     description?: string|null,
     *     meeting_count: int,
     *     price: int,
     *     stripe_price_id?: string|null,
     *     sort_order?: int|null
     * } $validated
     */
    public function __invoke(MeetingPack $plan, array $validated, User $auth): MeetingPack
    {
        return DB::transaction(function () use ($plan, $validated, $auth) {
            $plan->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'meeting_count' => $validated['meeting_count'],
                'price' => $validated['price'],
                'stripe_price_id' => $validated['stripe_price_id'] ?? null,
                'sort_order' => $validated['sort_order'] ?? $plan->sort_order,
                'updated_by_user_id' => $auth->id,
            ]);

            return $plan->fresh();
        });
    }
}
