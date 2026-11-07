<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;

/**
 * Enrollment の修了可否を判定する Service。
 *
 * 判定ロジック:
 * 対象資格に紐付く公開模試(is_published=true)の件数と、
 * 当該 Enrollment 配下の MockExamSession で pass=true かつ
 * 対象資格の公開模試に対する DISTINCT な mock_exam_id の件数が
 * 一致したとき eligible とする。
 *
 * 公開模試が 1 件もない場合は常に false。
 *
 * 利用先: 受講生ダッシュボード /
 * ReceiveCertificateAction
 *
 * final 不採用: Mockery によるモック化を可能にするため。
 */
class CompletionEligibilityService
{
    public function isEligible(Enrollment $enrollment): bool
    {
        $publishedExams = MockExam::query()
            ->where('certification_id', $enrollment->certification_id)
            ->where('is_published', true);

        $publishedCount = (clone $publishedExams)->count();

        if ($publishedCount === 0) {
            return false;
        }

        $passedCount = MockExamSession::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('pass', true)
            ->whereIn(
                'mock_exam_id',
                (clone $publishedExams)->select('id')
            )
            ->distinct()
            ->count('mock_exam_id');

        return $passedCount === $publishedCount;
    }
}
