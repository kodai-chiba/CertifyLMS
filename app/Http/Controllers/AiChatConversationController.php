<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AiChat\StoreConversationRequest;
use App\Http\Requests\AiChat\StoreMessageRequest;
use App\Http\Requests\AiChat\UpdateConversationRequest;
use App\Models\AiChatConversation;
use App\Models\Section;
use App\UseCases\AiChat\DestroyAction;
use App\UseCases\AiChat\IndexAction;
use App\UseCases\AiChat\SendMessageAction;
use App\UseCases\AiChat\ShowAction;
use App\UseCases\AiChat\StoreAction;
use App\UseCases\AiChat\UpdateAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AiChatConversationController extends Controller
{
    public function index(
        Request $request,
        IndexAction $action
    ): View|RedirectResponse {
        $this->authorize('viewAny', AiChatConversation::class);

        $conversations = $action($request->user());

        if ($conversations->isNotEmpty()) {
            return redirect()->route(
                'ai-chat.conversations.show',
                $conversations->first()
            );
        }

        return view('ai-chat.empty-state');
    }

    public function store(
        StoreConversationRequest $request,
        StoreAction $storeAction,
        SendMessageAction $sendMessageAction
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        $validated = $request->validated();

        $sectionId = $validated['section_id'] ?? null;
        $enrollmentId = null;

        if ($sectionId !== null) {
            $section = Section::query()
                ->with('chapter.part')
                ->findOrFail($sectionId);

            $certificationId = $section->chapter->part->certification_id;

            $enrollmentId = $user->enrollments()
                ->where('certification_id', $certificationId)
                ->whereIn('status', ['learning', 'passed'])
                ->value('id');
        }

        $conversation = $storeAction($user, [
            'enrollment_id' => $enrollmentId,
            'section_id' => $sectionId,
        ]);

        $message = trim((string) ($validated['message'] ?? ''));

        if ($message !== '') {
            try {
                $sendMessageAction($user, $conversation, $message);
            } catch (RuntimeException $exception) {
                if ($exception instanceof HttpExceptionInterface) {
                    throw $exception;
                }

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'AIサービスから回答を取得できませんでした。',
                        'conversation' => $conversation->refresh(),
                    ], 502);
                }

                return redirect()->route(
                    'ai-chat.conversations.show',
                    $conversation
                );
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'conversation' => $conversation->refresh(),
            ], 201);
        }

        return redirect()->route(
            'ai-chat.conversations.show',
            $conversation
        );
    }

    public function show(
        Request $request,
        AiChatConversation $conversation,
        ShowAction $action
    ): View|JsonResponse {
        $this->authorize('view', $conversation);

        $conversation = $action($conversation);

        if ($request->expectsJson()) {
            return response()->json([
                'conversation' => $conversation,
                'messages' => $conversation->messages,
            ]);
        }

        return view('ai-chat.show', [
            'conversation' => $conversation,
        ]);
    }

    public function update(
        UpdateConversationRequest $request,
        AiChatConversation $conversation,
        UpdateAction $action
    ): RedirectResponse|JsonResponse {
        $conversation = $action(
            $conversation,
            $request->validated('title')
        );

        if ($request->expectsJson()) {
            return response()->json([
                'conversation' => $conversation,
            ]);
        }

        return redirect()->route(
            'ai-chat.conversations.show',
            $conversation
        );
    }

    public function destroy(
        AiChatConversation $conversation,
        DestroyAction $action
    ): RedirectResponse {
        $this->authorize('delete', $conversation);

        $action($conversation);

        return redirect()->route('ai-chat.index');
    }

    public function storeMessage(
        StoreMessageRequest $request,
        AiChatConversation $conversation,
        SendMessageAction $action
    ): JsonResponse {
        try {
            $result = $action(
                $request->user(),
                $conversation,
                $request->validated('content')
            );
        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return response()->json([
                'message' => 'AIサービスから回答を取得できませんでした。',
            ], 502);
        }

        return response()->json($result);
    }
}
