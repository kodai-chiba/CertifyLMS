<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\User;
use App\Services\MeetingQuotaService;

/**
 * 面談画面に表示する残面談回数を取得する。
 */
final class GetRemainingQuotaAction
{
    public function __construct(
        private readonly MeetingQuotaService $meetingQuotaService,
    ) {}

    public function __invoke(User $user): int
    {
        return $this->meetingQuotaService->remaining($user);
    }
}
