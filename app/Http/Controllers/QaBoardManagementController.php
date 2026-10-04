<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Http\Requests\QaThread\IndexRequest;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\UseCases\QaReply\DestroyAction as DestroyReplyAction;
use App\UseCases\QaThread\DestroyAction as DestroyThreadAction;
use App\UseCases\QaThread\IndexAction;
use App\UseCases\QaThread\ShowAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 管理者向け質問掲示板のモデレーション Controller。
 *
 * 公開状態を問わず全資格の質問を閲覧し、質問・回答の削除を行う。
 * 管理者による投稿内容の編集、解決状態の変更、回答投稿は提供しない。
 */
class QaBoardManagementController extends Controller
{
    public function index(
        IndexRequest $request,
        IndexAction $action,
    ): View {
        $this->authorize('viewAny', QaThread::class);

        $filters = $request->filters();
        $user = $request->user();

        $threads = $action(
            viewer: $user,
            certificationId: $filters['certification_id'],
            status: isset($filters['status'])
                ? QaThreadStatus::from($filters['status'])
                : null,
            keyword: $filters['keyword'],
        );

        $certifications = Certification::query()
            ->orderBy('name')
            ->get();

        return view('qa-thread.index', [
            'threads' => $threads,
            'filters' => $filters,
            'certifications' => $certifications,
            'publishedStatus' => CertificationStatus::Published,
        ]);
    }

    public function show(
        QaThread $thread,
        ShowAction $action,
    ): View {
        $this->authorize('view', $thread);

        $thread = $action($thread);

        return view('qa-thread.show', [
            'thread' => $thread,
        ]);
    }

    public function destroyThread(
        QaThread $thread,
        DestroyThreadAction $action,
    ): RedirectResponse {
        $this->authorize('delete', $thread);

        $action($thread);

        return redirect()
            ->route('admin.qa-board.index')
            ->with('success', '質問を削除しました。');
    }

    public function destroyReply(
        QaThread $thread,
        QaReply $reply,
        DestroyReplyAction $action,
    ): RedirectResponse {
        abort_unless($reply->qa_thread_id === $thread->id, 404);

        $this->authorize('delete', $reply);

        $action($reply);

        return redirect()
            ->route('admin.qa-board.show', $thread)
            ->with('success', '回答を削除しました。');
    }
}
