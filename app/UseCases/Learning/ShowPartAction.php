<?php

declare(strict_types=1);

namespace App\UseCases\Learning;

use App\Enums\CertificationStatus;
use App\Enums\ContentStatus;
use App\Models\Part;
use App\Models\User;
use App\Services\Learning\LearningProgressService;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /learning/parts/{part} (3 階層目、Chapter 一覧) のデータを準備する Action。
 *
 * Part と親資格が Published であることを確認し、非公開の場合は 404 を返す。
 * 公開状態の確認後、learning.part.view Gate で受講登録を検証し、
 * 未登録または閲覧不可の受講状態であれば 403 を返す。
 *
 * 公開済 Chapter 一覧に加え、各 Chapter の Section 総数 / 読了済 Section 数を
 * Blade に渡す (Chapter 完了バッジの表示用)。
 * 読了済 Section 数の集計は LearningProgressService に委譲する。
 */
final class ShowPartAction
{
    public function __construct(
        private readonly LearningProgressService $progressService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Part $part, User $student): array
    {
        $part->loadMissing('certification');

        if ($part->status !== ContentStatus::Published
            || $part->certification === null
            || $part->certification->status !== CertificationStatus::Published) {
            throw new NotFoundHttpException;
        }

        Gate::forUser($student)->authorize('learning.part.view', $part);

        $chapters = $part->chapters()
            ->where('status', ContentStatus::Published->value)
            ->ordered()
            ->withCount([
                'sections as sections_total_count' => fn ($q) => $q
                    ->where('status', ContentStatus::Published->value),
            ])
            ->get();

        $enrollment = $student->enrollments()
            ->where('certification_id', $part->certification_id)
            ->first();

        $completedByChapter = $this->progressService
            ->countCompletedSectionsByChapter($enrollment, $chapters);

        return [
            'part' => $part->load('certification'),
            'chapters' => $chapters,
            'completedByChapter' => $completedByChapter,
        ];
    }
}
