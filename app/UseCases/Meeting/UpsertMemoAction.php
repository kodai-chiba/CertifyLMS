<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use Illuminate\Support\Facades\DB;

/**
 * 担当コーチによる面談メモの作成・更新処理。
 *
 * 予約済み・完了済みの面談のみメモを保存できる。
 * キャンセル済みなどの面談への保存は拒否する。
 */
final class UpsertMemoAction
{
    public function __invoke(Meeting $meeting, string $body): void
    {
        DB::transaction(function () use ($meeting, $body): void {
            if (! in_array($meeting->status, [MeetingStatus::Reserved, MeetingStatus::Completed], true)) {
                throw MeetingStatusTransitionException::forMemo();
            }

            MeetingMemo::updateOrCreate(
                ['meeting_id' => $meeting->id],
                ['body' => $body],
            );
        });
    }
}
