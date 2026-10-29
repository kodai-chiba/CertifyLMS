<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;

/**
 * 個人学習目標の更新 Action。
 */
final class UpdateAction
{
    /**
     * @param array{
     *     title: string,
     *     target_date?: ?string,
     *     description?: ?string
     * } $validated
     */
    public function __invoke(
        EnrollmentGoal $goal,
        array $validated,
    ): EnrollmentGoal {
        $goal->update([
            'title' => $validated['title'],
            'target_date' => $validated['target_date'] ?? null,
            'description' => $validated['description'] ?? null,
        ]);

        return $goal->refresh();
    }
}
