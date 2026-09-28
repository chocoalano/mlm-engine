<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Loader2, Search } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useId,
    watch,
} from 'vue';
import { Button } from '@/components/ui/button';
import { safeUrl } from '@/lib/utils';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { usePanel } from '@/panel/composables/usePanel';
import { resolveIcon } from '@/panel/icons/registry';
import type { PanelSearchGroup } from '@/panel/types/panel';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * The panel's command palette.
 *
 * Renders nothing unless the panel says searching is on *and* a resource in
 * it opted in — a palette that can only ever answer nothing is worse than no
 * palette.
 *
 * Results are whole: the server has already authorized the resource,
 * resolved each title and detail, and generated each URL. Nothing here
 * decides what a record is or where it lives.
 */
const { search } = usePanel();

/**
 * What the palette is currently doing.
 *
 * One value rather than a set of booleans, because the states genuinely
 * exclude each other and the old shape could not tell three of them apart: a
 * failed request assigned `[]`, so a server error and "nothing matches" were
 * the same screen, and a network failure returned early and left the previous
 * results standing with nothing to say they were about a different query.
 *
 *   idle      nothing asked yet, or fewer characters than the minimum
 *   loading   a request is out and there is nothing to show behind it
 *   results   the last request answered with something
 *   empty     the last request answered with nothing
 *   error     the last request did not answer
 *   stale     the last request did not answer, and older results are still up
 */
type SearchState = 'idle' | 'loading' | 'results' | 'empty' | 'error' | 'stale';

const open = ref(false);
const term = ref('');
const groups = ref<PanelSearchGroup[]>([]);
const state = ref<SearchState>('idle');
const active = ref(0);

/**
 * Unique per instance, so two palettes on one page cannot both claim to own
 * `panel-search-listbox`. `useId()` is Vue's own per-application counter — the
 * same mechanism Reka uses for its primitives.
 */
const uid = useId();

const ids = {
    input: `${uid}-input`,
    listbox: `${uid}-listbox`,
    status: `${uid}-status`,
    option: (index: number) => `${uid}-option-${index}`,
};

const enabled = computed(
    () => search.value.enabled && search.value.url !== null,
);
const hasResults = computed(() => groups.value.length > 0);
const searching = computed(() => state.value === 'loading');
const failed = computed(
    () => state.value === 'error' || state.value === 'stale',
);

/**
 * Results flattened in the order they are drawn, so the arrow keys walk the
 * list the user sees rather than the groups it arrived in.
 */
const flat = computed(() => groups.value.flatMap((group) => group.results));

function isActive(url: string): boolean {
    return flat.value[active.value]?.url === url;
}

/** The flat position of a result, which is what its option id is built from. */
function optionIndex(url: string): number {
    return flat.value.findIndex((result) => result.url === url);
}

/**
 * The option the input currently points at, or nothing.
 *
 * `aria-activedescendant` must name an element that exists: pointing it at an
 * id nothing renders is worse than leaving it off, because assistive
 * technology then reports no active option at all while the input insists
 * there is one.
 */
const activeOptionId = computed(() =>
    hasResults.value && flat.value[active.value] !== undefined
        ? ids.option(active.value)
        : undefined,
);

/**
 * Injected so a test can observe it. happy-dom has no layout, so a real
 * `scrollIntoView` neither does nor proves anything there — what can be
 * checked is that the *right* element was asked.
 */
const scrollActiveIntoView = (element: HTMLElement): void => {
    element.scrollIntoView?.({ block: 'nearest' });
};

defineExpose({ scrollActiveIntoView });

watch(active, async () => {
    const id = activeOptionId.value;

    if (id === undefined) {
        return;
    }

    await nextTick();

    const element = document.getElementById(id);

    if (element !== null) {
        scrollActiveIntoView(element);
    }
});

function resultUrl(url: string): string {
    return safeUrl(url) ?? '#';
}

/**
 * Arrows wrap, which is what a short list wants: up from the first result
 * reaches the last rather than doing nothing.
 */
function move(delta: number): void {
    const count = flat.value.length;

    if (count === 0) {
        return;
    }

    active.value = (active.value + delta + count) % count;
}

function onListKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        move(1);

        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(-1);

        return;
    }

    // Home and End, because a combobox that answers the arrow keys and not
    // these is a list you can only walk. Both are no-ops with nothing to
    // move to, which `move()` already handles.
    if (event.key === 'Home' && flat.value.length > 0) {
        event.preventDefault();
        active.value = 0;

        return;
    }

    if (event.key === 'End' && flat.value.length > 0) {
        event.preventDefault();
        active.value = flat.value.length - 1;

        return;
    }

    if (event.key === 'Enter') {
        const result = flat.value[active.value];
        const url = safeUrl(result?.url);

        if (url) {
            event.preventDefault();
            open.value = false;
            router.visit(url);
        }
    }
}

let timer: ReturnType<typeof setTimeout> | null = null;
let inFlight: AbortController | null = null;

/**
 * Validated rather than asserted: this crosses the same boundary an Inertia
 * prop does, so a malformed group degrades to nothing instead of throwing
 * inside the dialog.
 */
function toGroups(payload: unknown): PanelSearchGroup[] {
    if (typeof payload !== 'object' || payload === null) {
        return [];
    }

    const raw = (payload as { groups?: unknown }).groups;

    if (!Array.isArray(raw)) {
        return [];
    }

    return raw.filter((group): group is PanelSearchGroup => {
        if (typeof group !== 'object' || group === null) {
            return false;
        }

        const candidate = group as Partial<PanelSearchGroup>;

        return (
            typeof candidate.label === 'string' &&
            Array.isArray(candidate.results)
        );
    });
}

async function run(value: string): Promise<void> {
    const url = search.value.url;

    if (url === null || value.trim().length < 2) {
        groups.value = [];
        state.value = 'idle';

        return;
    }

    // One request at a time: a slower earlier answer must not overwrite a
    // faster later one. Unchanged — the state machine is layered on top of
    // the race safety that was already here, not in place of it.
    inFlight?.abort();
    inFlight = new AbortController();

    state.value = 'loading';

    try {
        const response = await fetch(`${url}?q=${encodeURIComponent(value)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: inFlight.signal,
        });

        if (!response.ok) {
            // A refusal is not an absence. Assigning `[]` here is what made a
            // 500 look exactly like a search that matched nothing.
            fail();

            return;
        }

        groups.value = toGroups(await response.json());
        state.value = hasResults.value ? 'results' : 'empty';

        // New answers, new selection: keeping the old index would highlight
        // whatever happens to sit there now.
        active.value = 0;
    } catch (error) {
        // Aborting is the normal case while typing — a later request is
        // already on its way and will say what the state is.
        if (error instanceof DOMException && error.name === 'AbortError') {
            return;
        }

        fail();
    }
}

/**
 * What a failed request leaves on screen.
 *
 * Results already up are kept, because throwing away what somebody can still
 * read is worse than a stale label — but they are marked as belonging to an
 * earlier query rather than presented as the answer to this one.
 */
function fail(): void {
    state.value = hasResults.value ? 'stale' : 'error';
}

function retry(): void {
    void run(term.value);
}

/**
 * One sentence for the live region, chosen by state.
 *
 * `idle` says nothing at all: announcing "type at least two characters" on
 * every keystroke below the minimum is the kind of live region people turn
 * off.
 */
const statusMessage = computed(() => {
    if (state.value === 'loading') {
        return t('shell.search_searching');
    }

    if (state.value === 'error') {
        return t('shell.search_failed');
    }

    if (state.value === 'stale') {
        return t('shell.search_stale');
    }

    if (state.value === 'empty') {
        return t('shell.search_empty');
    }

    if (state.value === 'results') {
        return t('shell.search_result_count', { count: flat.value.length });
    }

    return '';
});

watch(term, (value) => {
    if (timer !== null) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => void run(value), search.value.debounce);
});

/**
 * `mod` is the platform's command key, so one binding covers both.
 */
function matches(binding: string, event: KeyboardEvent): boolean {
    const parts = binding.toLowerCase().split('+');
    const key = parts[parts.length - 1];

    if (event.key.toLowerCase() !== key) {
        return false;
    }

    return parts.slice(0, -1).every((modifier) => {
        if (modifier === 'mod') {
            return event.metaKey || event.ctrlKey;
        }

        if (modifier === 'shift') {
            return event.shiftKey;
        }

        if (modifier === 'alt') {
            return event.altKey;
        }

        return false;
    });
}

function onKeydown(event: KeyboardEvent): void {
    if (!enabled.value) {
        return;
    }

    if (search.value.keyBindings.some((binding) => matches(binding, event))) {
        event.preventDefault();
        open.value = true;
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);

    if (timer !== null) {
        clearTimeout(timer);
    }

    inFlight?.abort();
});

// A visit means the user found what they wanted.
router.on('start', () => {
    open.value = false;
});
</script>

<template>
    <Dialog v-if="enabled" v-model:open="open">
        <Button
            variant="ghost"
            size="icon-sm"
            :aria-label="t('shell.search')"
            @click="open = true"
        >
            <Search />
        </Button>

        <DialogContent class="max-w-xl gap-0 p-0">
            <DialogHeader class="border-b px-4 py-3">
                <DialogTitle class="sr-only">{{
                    t('shell.search')
                }}</DialogTitle>
                <DialogDescription class="sr-only">
                    {{ t('shell.search_description') }}
                </DialogDescription>

                <div class="flex items-center gap-2">
                    <Search class="size-4 shrink-0 text-muted-foreground" />
                    <!--
                        A placeholder is not a label: it is gone the moment
                        anything is typed, and it is not what assistive
                        technology reads. The combobox attributes are written
                        here rather than adopted from a primitive because the
                        search engine, its debounce and its abort handling
                        already exist and work — this adds the semantics
                        around them instead of replacing them.
                    -->
                    <Input
                        :id="ids.input"
                        v-model="term"
                        autofocus
                        role="combobox"
                        autocomplete="off"
                        aria-autocomplete="list"
                        :aria-label="t('shell.search_label')"
                        :aria-expanded="hasResults"
                        :aria-controls="ids.listbox"
                        :aria-activedescendant="activeOptionId"
                        :placeholder="t('shell.search_placeholder')"
                        class="border-0 shadow-none focus-visible:ring-0"
                        @keydown="onListKeydown"
                    />
                    <Loader2
                        v-if="searching"
                        class="size-4 shrink-0 animate-spin text-muted-foreground"
                    />
                </div>
            </DialogHeader>

            <!--
                Restrained on purpose: it says what happened, not what was
                typed, so it does not narrate every keystroke.
            -->
            <p
                :id="ids.status"
                role="status"
                aria-live="polite"
                class="sr-only"
            >
                {{ statusMessage }}
            </p>

            <div class="max-h-96 overflow-y-auto p-2">
                <!--
                    Four different things that are not each other: too short to
                    ask, asking, asked and got nothing, and asked and failed.
                    The last two were the same screen.
                -->
                <div
                    v-if="failed"
                    class="flex flex-col items-center gap-2 px-2 py-6 text-center text-sm"
                >
                    <p class="text-destructive">
                        {{
                            state === 'stale'
                                ? t('shell.search_stale')
                                : t('shell.search_failed')
                        }}
                    </p>
                    <Button variant="outline" size="sm" @click="retry">
                        {{ t('shell.retry') }}
                    </Button>
                </div>

                <p
                    v-else-if="!hasResults"
                    class="px-2 py-6 text-center text-sm text-muted-foreground"
                >
                    {{
                        term.trim().length < 2
                            ? t('shell.search_too_short')
                            : t('shell.search_empty')
                    }}
                </p>

                <div
                    :id="ids.listbox"
                    role="listbox"
                    :aria-label="t('shell.search_results')"
                    :aria-busy="searching ? 'true' : undefined"
                    class="flex flex-col"
                    :class="state === 'stale' ? 'opacity-60' : ''"
                >
                    <div
                        v-for="group in groups"
                        :key="group.resource"
                        role="group"
                        :aria-label="group.label"
                        class="flex flex-col gap-1 py-1"
                    >
                        <p
                            aria-hidden="true"
                            class="flex items-center gap-2 px-2 py-1 text-xs font-medium text-muted-foreground"
                        >
                            <component
                                :is="resolveIcon(group.icon)"
                                v-if="resolveIcon(group.icon)"
                                class="size-3.5"
                            />
                            {{ group.label }}
                        </p>

                        <Link
                            v-for="result in group.results"
                            :id="ids.option(optionIndex(result.url))"
                            :key="result.url"
                            :href="resultUrl(result.url)"
                            role="option"
                            :data-active="
                                isActive(resultUrl(result.url))
                                    ? 'true'
                                    : undefined
                            "
                            :aria-selected="isActive(resultUrl(result.url))"
                            class="flex flex-col gap-0.5 rounded-md px-2 py-2 text-sm transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none data-[active=true]:bg-accent data-[active=true]:text-accent-foreground"
                        >
                            <span class="font-medium">{{ result.title }}</span>
                            <span
                                v-if="Object.keys(result.details).length > 0"
                                class="flex flex-wrap gap-x-3 text-xs text-muted-foreground"
                            >
                                <span
                                    v-for="(value, key) in result.details"
                                    :key="key"
                                >
                                    {{ key }}: {{ value }}
                                </span>
                            </span>
                        </Link>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
