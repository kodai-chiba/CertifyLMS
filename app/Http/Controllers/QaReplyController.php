<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\QaReply\StoreRequest;
use App\Http\Requests\QaReply\UpdateRequest;
use App\Models\QaReply;
use App\Models\QaThread;
use App\UseCases\QaReply\DestroyAction;
use App\UseCases\QaReply\StoreAction;
use App\UseCases\QaReply\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class QaReplyController extends Controller
{
    public function store(
        StoreRequest $request,
        QaThread $thread,
        StoreAction $action,
    ): RedirectResponse {
        $action(
            thread: $thread,
            user: $request->user(),
            validated: $request->validated(),
        );

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '返信を投稿しました。');
    }

    public function edit(
        QaThread $thread,
        QaReply $reply,
    ): View {
        $this->authorize('update', $reply);

        abort_unless($reply->qa_thread_id === $thread->id, 404);

        $thread->load('certification');

        return view('qa-thread.reply-edit', [
            'thread' => $thread,
            'reply' => $reply,
        ]);
    }

    public function update(
        UpdateRequest $request,
        QaThread $thread,
        QaReply $reply,
        UpdateAction $action,
    ): RedirectResponse {
        abort_unless($reply->qa_thread_id === $thread->id, 404);

        $reply = $action(
            reply: $reply,
            validated: $request->validated(),
        );

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '返信を更新しました。');
    }

    public function destroy(
        QaThread $thread,
        QaReply $reply,
        DestroyAction $action,
    ): RedirectResponse {
        abort_unless($reply->qa_thread_id === $thread->id, 404);

        $this->authorize('delete', $reply);

        $action($reply);

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '返信を削除しました。');
    }
}
