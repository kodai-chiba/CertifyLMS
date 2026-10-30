/**
 * 通知ベルのポップオーバーを制御する。
 * 通知の取得・タブ切り替え・既読処理を行う。
 */
export function initNotificationPopover() {
    const root = document.querySelector('[data-notification-popover-root]');

    if (!root) {
        return;
    }

    const trigger = root.querySelector('[data-notification-popover-trigger]');
    const panel = root.querySelector('[data-notification-popover-panel]');
    const badge = root.querySelector('[data-notification-popover-badge]');
    const tabs = root.querySelectorAll('[data-notification-popover-tab]');
    const unreadCount = root.querySelector('[data-notification-popover-unread-count]');
    const markAllButton = root.querySelector('[data-notification-popover-mark-all]');
    const loading = root.querySelector('[data-notification-popover-loading]');
    const empty = root.querySelector('[data-notification-popover-empty]');
    const items = root.querySelector('[data-notification-popover-items]');
    const template = root.querySelector('[data-notification-popover-row-template]');

    if (!trigger || !panel || !items || !template) {
        return;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    let activeTab = 'all';
    let isOpen = false;

    function updateBadge(count) {
        const value = Number(count) || 0;

        if (badge) {
            badge.textContent = value > 99 ? '99+' : String(value);
            badge.classList.toggle('hidden', value === 0);
        }

        if (unreadCount) {
            unreadCount.textContent = String(value);
        }

        trigger.setAttribute('aria-label', `通知 (${value} 件未読)`);
    }

    function setOpen(open) {
        isOpen = open;

        panel.classList.toggle('hidden', !open);
        panel.classList.toggle('flex', open);
        panel.style.display = open ? 'flex' : 'none';
        panel.classList.toggle('opacity-0', !open);
        panel.classList.toggle('-translate-y-1', !open);

        trigger.setAttribute('aria-expanded', String(open));
    }

    function setLoading(value) {
        loading?.classList.toggle('hidden', !value);
        items.classList.toggle('hidden', value);

        // 読み込み開始時だけ空表示を隠す。
        // 読み込み完了後の表示は renderNotifications() に任せる。
        if (value) {
            empty?.classList.add('hidden');
        }
    }

    function updateTabs() {
        tabs.forEach((tab) => {
            tab.setAttribute(
                'aria-selected',
                String(tab.dataset.notificationPopoverTab === activeTab),
            );
        });
    }

    function formatDate(value) {
        if (!value) {
            return '';
        }

        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return '';
        }

        return new Intl.DateTimeFormat('ja-JP', {
            month: 'numeric',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }).format(date);
    }

    function renderNotifications(notifications) {
        items.replaceChildren();

        if (empty) {
            empty.textContent = '通知はありません。';
            empty.classList.toggle('hidden', notifications.length > 0);
        }

        notifications.forEach((notification) => {
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-notification-popover-row]');
            const dot = fragment.querySelector('[data-notification-popover-row-dot]');
            const title = fragment.querySelector('[data-notification-popover-row-title]');
            const message = fragment.querySelector('[data-notification-popover-row-message]');
            const time = fragment.querySelector('[data-notification-popover-row-time]');

            if (!row) {
                return;
            }

            row.href = '#';
            row.dataset.notificationId = notification.id;
            row.dataset.notificationUrl = notification.url;

            const isUnread = notification.read_at === null;

            row.dataset.unread = String(isUnread);
            dot?.classList.toggle('invisible', !isUnread);

            if (title) {
                title.textContent = notification.title;
            }

            if (message) {
                message.textContent = notification.message;
            }

            if (time) {
                time.textContent = formatDate(notification.created_at);
            }

            items.appendChild(fragment);
        });
    }

    async function loadNotifications() {
        setLoading(true);

        try {
            const response = await fetch(
                `/notifications/feed?tab=${encodeURIComponent(activeTab)}`,
                {
                    headers: {
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                },
            );

            if (!response.ok) {
                throw new Error('通知の取得に失敗しました。');
            }

            const data = await response.json();

            renderNotifications(data.notifications ?? []);
            updateBadge(data.unread_count ?? 0);
        } catch (error) {
            console.error(error);
            items.replaceChildren();

            if (empty) {
                empty.textContent = '通知を取得できませんでした。';
                empty.classList.remove('hidden');
            }
        } finally {
            setLoading(false);
        }
    }

    trigger.addEventListener('click', () => {
        setOpen(!isOpen);

        if (isOpen) {
            loadNotifications();
        }
    });

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            activeTab = tab.dataset.notificationPopoverTab;
            updateTabs();
            loadNotifications();
        });
    });

    items.addEventListener('click', (event) => {
        const row = event.target.closest('[data-notification-popover-row]');

        if (!row) {
            return;
        }

        event.preventDefault();

        const notificationUrl = row.dataset.notificationUrl;

        if (!notificationUrl || !csrfToken) {
            return;
        }

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = notificationUrl;

        const tokenInput = document.createElement('input');
        tokenInput.type = 'hidden';
        tokenInput.name = '_token';
        tokenInput.value = csrfToken;

        form.appendChild(tokenInput);
        document.body.appendChild(form);
        form.submit();
    });

    markAllButton?.addEventListener('click', async () => {
        if (!csrfToken) {
            return;
        }

        markAllButton.disabled = true;

        try {
            const response = await fetch('/notifications/read-all', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error('既読処理に失敗しました。');
            }

            await loadNotifications();
        } catch (error) {
            console.error(error);
            alert('通知を既読にできませんでした。');
        } finally {
            markAllButton.disabled = false;
        }
    });

    document.addEventListener('click', (event) => {
        if (isOpen && !root.contains(event.target)) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen) {
            setOpen(false);
            trigger.focus();
        }
    });
}