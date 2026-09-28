<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { safeUrl } from '@/lib/utils';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { usePanel } from '@/panel/composables/usePanel';
import { postJson } from '@/panel/forms/http';
import { resolveIcon } from '@/panel/icons/registry';
import { ICON_CLASSES } from '@/panel/palette';
import type {
    NotificationColorName,
    PanelNotificationAction,
    PanelNotificationItem,
} from '@/panel/types/panel';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * The notification bell and the centre behind it.
 *
 * The unread count arrives with every panel request, so the badge is right
 * after any navigation without polling. The list itself is fetched only when
 * the bell is opened — a notification nobody looked at costs nothing.
 *
 * A broadcast that says a row was written refetches the list if it is open
 * and bumps the count if it is not. There is one subscription for toasts and
 * notifications both, in `usePanelBroadcasting`, which raises the event this
 * listens for.
 */
const { notifications: settings } = usePanel();

const open = ref(false);
const loading = ref(false);
const failed = ref(false);
const items = ref<PanelNotificationItem[]>([]);

/**
 * Local, because a notification read here must stop being counted before the
 * next page load. It falls back to the server's number, which is the one that
 * survives a navigation.
 */
const unread = ref<number | null>(null);

const count = computed(() => unread.value ?? settings.value.unread);

const COLOR_CLASSES: Record<NotificationColorName, string> =
    ICON_CLASSES as Record<NotificationColorName, string>;

/**
 * Narrowed rather than asserted: this crosses from the server as untyped JSON
 * like every other payload, and a shape that does not match must leave the
 * list as it was rather than throwing inside the renderer.
 */
function toItems(payload: unknown): PanelNotificationItem[] | null {
    if (typeof payload !== 'object' || payload === null) {
        return null;
    }

    const list = (payload as { notifications?: unknown }).notifications;

    if (!Array.isArray(list)) {
        return null;
    }

    const narrowed: PanelNotificationItem[] = [];

    for (const entry of list) {
        if (
            typeof entry === 'object' &&
            entry !== null &&
            typeof (entry as PanelNotificationItem).id === 'string' &&
            typeof (entry as PanelNotificationItem).title === 'string'
        ) {
            narrowed.push(entry as PanelNotificationItem);
        }
    }

    return narrowed;
}

function toUnread(payload: unknown): number | null {
    const value = (payload as { unread?: unknown } | null)?.unread;

    return typeof value === 'number' ? value : null;
}

async function load(): Promise<void> {
    const url = settings.value.indexUrl;

    if (url === null) {
        return;
    }

    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        const payload: unknown = response.ok ? await response.json() : null;
        const parsed = toItems(payload);

        failed.value = parsed === null;

        if (parsed !== null) {
            items.value = parsed;
            unread.value = toUnread(payload) ?? unread.value;
        }
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        void load();
    }
});

/**
 * Raised by `usePanelBroadcasting` when a persistent notification arrives.
 * A refetch rather than pushing the payload into the list: the broadcast does
 * not carry the row's id, and a list holding an entry that cannot be marked
 * read would be worse than one request.
 */
function onArrived(): void {
    if (open.value) {
        void load();

        return;
    }

    unread.value = count.value + 1;
}

if (typeof window !== 'undefined') {
    window.addEventListener('panel:notification', onArrived);
}

async function markRead(id: string | null): Promise<void> {
    const url = settings.value.readUrl;

    if (url === null) {
        return;
    }

    const payload = await postJson(url, id === null ? {} : { id });

    unread.value = toUnread(payload) ?? 0;

    items.value = items.value.map((item) =>
        id === null || item.id === id ? { ...item, read: true } : item,
    );
}

async function clear(all: boolean): Promise<void> {
    const url = settings.value.clearUrl;

    if (url === null) {
        return;
    }

    const payload = await postJson(url, { all });

    unread.value = toUnread(payload) ?? 0;
    items.value = all ? [] : items.value.filter((item) => !item.read);
}

/**
 * Following an action's link is an ordinary navigation. Whatever it points at
 * authorizes for itself when it is followed, which is the only check still
 * meaningful a week after the notification was written.
 */
async function run(
    item: PanelNotificationItem,
    action: PanelNotificationAction,
): Promise<void> {
    if (action.markAsRead) {
        await markRead(item.id);
    }

    const url = safeUrl(action.url);

    if (url === null) {
        return;
    }

    if (action.newTab) {
        window.open(url, '_blank', 'noopener');

        return;
    }

    open.value = false;
    router.visit(url);
}
</script>

<template>
    <Sheet v-if="settings.enabled" v-model:open="open">
        <SheetTrigger as-child>
            <Button
                variant="ghost"
                size="icon-sm"
                class="relative"
                :title="
                    count > 0
                        ? t('shell.unread_count', { count })
                        : t('shell.notifications')
                "
                :aria-label="t('shell.notifications')"
            >
                <Bell />
                <span
                    v-if="count > 0"
                    class="absolute -top-0.5 -right-0.5 flex min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] leading-4 font-medium text-destructive-foreground"
                >
                    {{ count > 99 ? '99+' : count }}
                </span>
            </Button>
        </SheetTrigger>

        <SheetContent side="right" class="gap-0 p-0 sm:max-w-md">
            <SheetHeader class="border-b px-4 py-3 pr-12">
                <SheetTitle>{{ t('shell.notifications') }}</SheetTitle>
                <SheetDescription>
                    {{
                        count > 0
                            ? t('shell.unread_count', { count })
                            : t('shell.notification_center')
                    }}
                </SheetDescription>
            </SheetHeader>

            <div
                v-if="count > 0 || items.length > 0"
                class="flex items-center justify-end border-b px-3 py-2"
            >
                <div class="flex items-center gap-1">
                    <Button
                        v-if="count > 0"
                        variant="ghost"
                        size="sm"
                        class="h-auto px-2 py-1 text-xs"
                        @click="markRead(null)"
                    >
                        {{ t('shell.mark_all_read') }}
                    </Button>
                    <Button
                        v-if="items.length > 0"
                        variant="ghost"
                        size="sm"
                        class="h-auto px-2 py-1 text-xs"
                        @click="clear(false)"
                    >
                        {{ t('shell.clear_read') }}
                    </Button>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <div v-if="loading" class="flex justify-center py-8">
                    <Spinner class="size-5" />
                </div>

                <p
                    v-else-if="failed"
                    class="px-3 py-8 text-center text-sm text-muted-foreground"
                >
                    {{ t('shell.notifications_failed') }}
                </p>

                <p
                    v-else-if="items.length === 0"
                    class="px-3 py-8 text-center text-sm text-muted-foreground"
                >
                    {{ t('shell.notifications_empty') }}
                </p>

                <ul v-else class="divide-y">
                    <li
                        v-for="item in items"
                        :key="item.id"
                        class="flex gap-3 px-3 py-3"
                        :class="item.read ? '' : 'bg-muted/40'"
                    >
                        <component
                            :is="resolveIcon(item.icon)"
                            v-if="resolveIcon(item.icon)"
                            class="mt-0.5 size-4 shrink-0"
                            :class="COLOR_CLASSES[item.color]"
                        />

                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <p class="text-sm font-medium">{{ item.title }}</p>
                            <p
                                v-if="item.body"
                                class="text-sm text-muted-foreground"
                            >
                                {{ item.body }}
                            </p>
                            <p
                                v-if="item.createdAt"
                                class="text-xs text-muted-foreground"
                            >
                                {{ item.createdAt }}
                            </p>

                            <div
                                v-if="item.actions.length > 0"
                                class="flex flex-wrap gap-1 pt-1"
                            >
                                <Button
                                    v-for="action in item.actions"
                                    :key="action.name"
                                    :variant="action.variant"
                                    size="sm"
                                    class="h-auto px-2 py-1 text-xs"
                                    @click="run(item, action)"
                                >
                                    {{ action.label }}
                                </Button>
                            </div>
                        </div>

                        <Button
                            v-if="!item.read"
                            variant="ghost"
                            size="sm"
                            class="h-auto shrink-0 px-2 py-1 text-xs"
                            :aria-label="t('shell.mark_as_read')"
                            @click="markRead(item.id)"
                        >
                            {{ t('shell.read') }}
                        </Button>
                    </li>
                </ul>
            </div>
        </SheetContent>
    </Sheet>
</template>
