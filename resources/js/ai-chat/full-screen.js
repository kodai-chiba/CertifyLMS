import { AiChatClient } from './chat-client.js';
import { renderFullScreenMessage } from './message-renderer.js';

/**
 * フル画面 AI 相談の挙動。
 */
function initForm() {
    const form = document.querySelector('[data-ai-chat-form]');
    if (!form) return;

    const textarea = form.querySelector('[data-ai-chat-textarea]');
    const submit = form.querySelector('[data-ai-chat-submit]');
    let list = document.querySelector('[data-message-list]');
    const scroller = document.querySelector('[data-message-scroller]');
    const titleEl = document.querySelector('[data-conversation-title]');

    if (!scroller) return;

    // 新規会話など、メッセージ一覧が存在しない場合は動的に生成する
    if (!list) {
        scroller.replaceChildren();

        list = document.createElement('ul');
        list.setAttribute('role', 'log');
        list.setAttribute('aria-live', 'polite');
        list.setAttribute('aria-atomic', 'false');
        list.setAttribute('aria-label', 'AI 相談メッセージ');
        list.className = 'flex flex-col gap-4 max-w-[760px] mx-auto w-full';
        list.dataset.messageList = '';

        scroller.appendChild(list);
    }

    const viewerName = document.querySelector('meta[name="auth-user-name"]')?.content
        ?? document.querySelector('.tb-user .nm')?.textContent?.trim()
        ?? 'YOU';

    function scrollToBottom() {
        if (scroller) scroller.scrollTop = scroller.scrollHeight;
    }

    function autoResize() {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(140, textarea.scrollHeight) + 'px';
    }

    function appendTyping() {
        const template = document.querySelector('[data-ai-chat-message-template]');
        if (!template) return null;

        const node = template.content.firstElementChild.cloneNode(true);
        node.dataset.typing = '1';

        const avatar = node.querySelector('[data-avatar]');
        const author = node.querySelector('[data-author]');
        const bubble = node.querySelector('[data-bubble]');
        const contentEl = node.querySelector('[data-message-content]');
        const timeEl = node.querySelector('[data-time]');

        avatar?.classList.add('bg-secondary-600');

        if (avatar) {
            avatar.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-3.5 h-3.5" aria-hidden="true"><path d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z"/></svg>';
        }

        if (author) author.textContent = 'AI';

        bubble?.classList.add(
            'bg-white',
            'text-ink-900',
            'rounded-tl-md',
            'shadow-sm',
            'border',
            'border-subtle'
        );

        if (contentEl) {
            contentEl.innerHTML = '<span class="inline-flex gap-1 py-1.5" aria-label="AI が応答を生成中"><span class="w-1.5 h-1.5 rounded-full bg-secondary-400 animate-bounce" style="animation-delay:0ms"></span><span class="w-1.5 h-1.5 rounded-full bg-secondary-400 animate-bounce" style="animation-delay:160ms"></span><span class="w-1.5 h-1.5 rounded-full bg-secondary-400 animate-bounce" style="animation-delay:320ms"></span></span>';
        }

        if (timeEl) timeEl.textContent = '';

        list.appendChild(node);

        return node;
    }

    let currentTyping = null;

    function clearTyping() {
        if (currentTyping) {
            currentTyping.remove();
            currentTyping = null;
        }
    }

    textarea.addEventListener('input', autoResize);

    textarea.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
            e.preventDefault();

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        }
    });

    const client = new AiChatClient({
        storeUrl: form.dataset.storeUrl,
        onUserMessage: () => {},

        onAssistantMessage: (msg) => {
            clearTyping();
            renderFullScreenMessage(list, msg, { viewerName });
        },

        onTitleUpdated: ({ title }) => {
            if (!title) return;

            if (titleEl) titleEl.textContent = title;

            const parts = document.title.split(' | ');
            document.title = parts.length > 1
                ? `${title} | ${parts[parts.length - 1]}`
                : title;
        },

        onError: (err) => {
            clearTyping();

            renderFullScreenMessage(list, {
                id: 'tmp-error-' + Date.now(),
                role: 'assistant',
                content: describeError(err),
                status: 'error',
                created_at: new Date().toISOString(),
            }, { viewerName });

            scrollToBottom();
        },
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const content = textarea.value.trim();
        if (!content) return;

        submit.disabled = true;
        textarea.value = '';
        textarea.style.height = '';
        autoResize();

        renderFullScreenMessage(list, {
            id: 'tmp-user-' + Date.now(),
            role: 'user',
            content,
            status: 'completed',
            created_at: new Date().toISOString(),
        }, { viewerName });

        currentTyping = appendTyping();
        scrollToBottom();

        try {
            await client.sendSync(content);
        } finally {
            submit.disabled = false;
            clearTyping();
            scrollToBottom();
        }
    });

    scrollToBottom();
}

function describeError(err) {
    if (err.type === 'rate-limit') {
        return '本日の利用上限に達しました。明日 0:00 以降に再度ご利用ください。';
    }

    if (err.type === 'validation') {
        return '入力内容を確認してください (1-2000 文字)。';
    }

    if (err.type === 'llm') {
        const upstream = err.upstreamStatus;

        if (upstream === 429) {
            return 'Gemini API のリクエスト制限に達しました。1 分ほど待って再試行してください。';
        }

        if ([500, 502, 503, 504].includes(upstream)) {
            return 'Gemini API が一時的に応答していません。少し待って再試行してください。';
        }

        return 'AI が応答できませんでした。しばらく時間をおいて再試行してください。';
    }

    return '送信に失敗しました。時間をおいて再度お試しください。';
}

function initNewConversationModal() {
    const form = document.getElementById('new-ai-chat-form');
    if (!form) return;

    form.addEventListener('submit', () => {
        const submitBtn = document.querySelector(
            'button[form="new-ai-chat-form"][type="submit"]'
        );

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = '開始中…';
        }
    });
}

export function initAiChatFullScreen() {
    initForm();
    initNewConversationModal();
}