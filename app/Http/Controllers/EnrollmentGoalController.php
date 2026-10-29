<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EnrollmentGoal\StoreRequest;
use App\Http\Requests\EnrollmentGoal\UpdateRequest;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\UseCases\EnrollmentGoal\DestroyAction;
use App\UseCases\EnrollmentGoal\MarkAchievedAction;
use App\UseCases\EnrollmentGoal\StoreAction;
use App\UseCases\EnrollmentGoal\UnmarkAchievedAction;
use App\UseCases\EnrollmentGoal\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 個人学習目標 Controller。
 *
 * 目標の作成・編集・削除・達成・未達成への変更は受講生本人のみ実行可能。
 * Coach / Admin は Enrollment 詳細画面から閲覧のみ行う。
 */
class EnrollmentGoalController extends Controller
{
    /**
     * 個人学習目標を登録する。
     */
    public function store(
        Enrollment $enrollment,
        StoreRequest $request,
        StoreAction $action,
    ): RedirectResponse {
        $action($enrollment, $request->validated());

        return redirect()
            ->route('enrollments.show', $enrollment)
            ->with('success', '個人学習目標を登録しました。');
    }

    /**
     * 個人学習目標の編集画面を表示する。
     */
    public function edit(EnrollmentGoal $goal): View
    {
        $this->authorize('update', $goal);

        return view('enrollment-goal.edit', [
            'goal' => $goal,
        ]);
    }

    /**
     * 個人学習目標を更新する。
     */
    public function update(
        EnrollmentGoal $goal,
        UpdateRequest $request,
        UpdateAction $action,
    ): RedirectResponse {
        $goal = $action($goal, $request->validated());

        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '個人学習目標を更新しました。');
    }

    /**
     * 個人学習目標を削除する。
     */
    public function destroy(
        EnrollmentGoal $goal,
        DestroyAction $action,
    ): RedirectResponse {
        $this->authorize('delete', $goal);

        $enrollmentId = $goal->enrollment_id;

        $action($goal);

        return redirect()
            ->route('enrollments.show', $enrollmentId)
            ->with('success', '個人学習目標を削除しました。');
    }

    /**
     * 個人学習目標を達成済みにする。
     */
    public function markAchieved(
        EnrollmentGoal $goal,
        MarkAchievedAction $action,
    ): RedirectResponse {
        $this->authorize('markAchieved', $goal);

        $goal = $action($goal);

        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '個人学習目標を達成済みにしました。');
    }

    /**
     * 個人学習目標を未達成に戻す。
     */
    public function unmarkAchieved(
        EnrollmentGoal $goal,
        UnmarkAchievedAction $action,
    ): RedirectResponse {
        $this->authorize('unmarkAchieved', $goal);

        $goal = $action($goal);

        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '個人学習目標を未達成に戻しました。');
    }
}
