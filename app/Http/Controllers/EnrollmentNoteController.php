<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EnrollmentNote\StoreRequest;
use App\Http\Requests\EnrollmentNote\UpdateRequest;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\UseCases\EnrollmentNote\DestroyAction;
use App\UseCases\EnrollmentNote\StoreAction;
use App\UseCases\EnrollmentNote\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 受講生メモの管理 Controller。
 *
 * - 担当コーチ: 担当資格の受講登録にメモを追加可能
 * - 担当コーチ: 自分のメモのみ編集・削除可能
 * - 管理者: すべての受講登録のメモを追加・編集・削除可能
 * - 受講生: すべての操作を禁止
 *
 * 認可は EnrollmentNotePolicy に委譲する。
 */
class EnrollmentNoteController extends Controller
{
    /**
     * メモを追加する。
     */
    public function store(
        StoreRequest $request,
        Enrollment $enrollment,
        StoreAction $action,
    ): RedirectResponse {
        $action($enrollment, $request->user(), $request->validated());

        return redirect()
            ->route('enrollments.show', $enrollment)
            ->with('success', 'メモを追加しました。');
    }

    /**
     * メモの編集画面を表示する。
     */
    public function edit(EnrollmentNote $note): View
    {
        $this->authorize('update', $note);

        return view('enrollment-note.edit', [
            'note' => $note,
        ]);
    }

    /**
     * メモを更新する。
     */
    public function update(
        UpdateRequest $request,
        EnrollmentNote $note,
        UpdateAction $action,
    ): RedirectResponse {
        $action($note, $request->validated());

        return redirect()
            ->route('enrollments.show', $note->enrollment_id)
            ->with('success', 'メモを更新しました。');
    }

    /**
     * メモを削除する。
     */
    public function destroy(
        EnrollmentNote $note,
        DestroyAction $action,
    ): RedirectResponse {
        $this->authorize('delete', $note);

        $enrollmentId = $note->enrollment_id;

        $action($note);

        return redirect()
            ->route('enrollments.show', $enrollmentId)
            ->with('success', 'メモを削除しました。');
    }
}
