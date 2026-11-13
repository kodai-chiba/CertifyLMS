<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentStatusLog;
use App\Models\User;

/**
 * Enrollment 状態遷移の監査ログ(EnrollmentStatusLog)を INSERT する Service。
 *
 * 呼出側 Action がトランザクション内で recordStatusChange() を呼ぶ前提。
 * 本 Service 自体は DB::transaction() を持たない。
 *
 * final 不採用: Mockery によるモック化を可能にするため。
 */
class EnrollmentStatusChangeService
{
    /**
     * @param Enrollment $enrollment 状態遷移する対象 Enrollment
     * @param ?EnrollmentStatus $fromStatus 遷移前ステータス
     * @param EnrollmentStatus $toStatus 遷移後ステータス
     * @param ?User $changedBy 操作者
     * @param ?string $reason 変更理由
     */
    public function recordStatusChange(
        Enrollment $enrollment,
        ?EnrollmentStatus $fromStatus,
        EnrollmentStatus $toStatus,
        ?User $changedBy,
        ?string $reason = null,
    ): EnrollmentStatusLog {
        return $enrollment->statusLogs()->create([
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus->value,
            'changed_by_user_id' => $changedBy?->id,
            'changed_reason' => $reason,
            'changed_at' => now(),
        ]);
    }
}
