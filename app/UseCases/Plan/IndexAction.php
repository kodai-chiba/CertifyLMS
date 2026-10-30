<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 受講プランマスタ一覧を取得するユースケース。
 */
final class IndexAction
{
    /**
     * @return LengthAwarePaginator<int, Plan>
     */
    public function __invoke(?string $keyword = null, ?string $status = null): LengthAwarePaginator
    {
        return Plan::query()
            ->withCount('users')
            ->when(
                $keyword,
                fn ($query, $keyword) => $query->where('name', 'like', "%{$keyword}%")
            )
            ->when(
                $status,
                fn ($query, $status) => $query->where('status', PlanStatus::from($status))
            )
            ->ordered()
            ->paginate(20)
            ->withQueryString();
    }
}
