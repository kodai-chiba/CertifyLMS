<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class IndexAction
{
    /**
     * @return LengthAwarePaginator<int, MeetingPack>
     */
    public function __invoke(?string $keyword = null, ?string $status = null): LengthAwarePaginator
    {
        return MeetingPack::query()
            ->with(['createdBy', 'updatedBy'])
            ->when(
                $keyword,
                fn ($query) => $query->where('name', 'like', '%'.$keyword.'%')
            )
            ->when(
                $status && MeetingPackStatus::tryFrom($status),
                fn ($query) => $query->where('status', $status)
            )
            ->ordered()
            ->paginate(20)
            ->withQueryString();
    }
}
