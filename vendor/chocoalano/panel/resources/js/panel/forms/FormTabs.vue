<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import FormComponentRenderer from '@/panel/forms/FormComponentRenderer.vue';
import { resolveIcon } from '@/panel/icons/registry';
import type { FormValue, FormValues, TabsDefinition } from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * A tab set, on Reka's primitive rather than on hand-written ARIA.
 *
 * The markup here used to be `role="tab"` on a plain button with
 * `aria-selected` and `aria-controls`, which is the half of the tabs pattern
 * that is visible in a DOM inspector. The other half is keyboard behaviour:
 * arrow keys move between tabs, Home and End jump to the ends, and only the
 * selected tab is a tab stop so that Tab moves *out* of the set rather than
 * through every tab in it. None of that was implemented, so a keyboard user
 * reaching a tab set could not change tabs at all.
 *
 * Writing that by hand is a few dozen lines of key handling and a roving
 * tabindex to keep in step with the selection. Reka already has it, is already
 * a dependency, and already generates ids per instance — which also fixes two
 * tab sets on one page both calling their panel `panel-details`.
 *
 * `unmount-on-hide="false"` keeps every panel in the DOM, which is what the
 * previous `v-show` did. It matters beyond preserving behaviour: a rejected
 * submit moves focus to the first invalid field, and a field that is not
 * rendered cannot be focused.
 */
const props = defineProps<{
    tabs: TabsDefinition;
    values: FormValues;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{ change: [name: string, value: FormValue] }>();

const active = ref(props.tabs.tabs[0]?.key ?? '');

/**
 * A tab holding a rejected field is opened for the user.
 *
 * Without this, submitting a long form and being told nothing happened is
 * exactly what it looks like: the message is on a panel that is not on
 * screen. The server sends each tab's field names for this reason.
 */
const failing = computed(() =>
    props.tabs.tabs.find((tab) =>
        tab.fields.some((name) => props.errors[name] !== undefined),
    ),
);

watch(failing, (tab) => {
    if (tab !== undefined) {
        active.value = tab.key;
    }
});

/**
 * Persisted in the URL when the schema asked for it, so a reload — or a link
 * someone was sent — opens where it was left.
 */
watch(active, (key) => {
    if (!props.tabs.persistTab || typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);

    url.searchParams.set('tab', key);
    window.history.replaceState({}, '', url);
});

if (props.tabs.persistTab && typeof window !== 'undefined') {
    const requested = new URL(window.location.href).searchParams.get('tab');

    // Only a key the schema declares. A URL naming a tab that does not exist
    // opens the first one rather than nothing at all.
    if (
        requested !== null &&
        props.tabs.tabs.some((tab) => tab.key === requested)
    ) {
        active.value = requested;
    }
}

function hasError(fields: string[]): boolean {
    return fields.some((name) => props.errors[name] !== undefined);
}
</script>

<template>
    <TabsRoot
        v-model="active"
        :unmount-on-hide="false"
        class="flex flex-col gap-4"
    >
        <TabsList class="flex flex-wrap gap-1 border-b">
            <TabsTrigger
                v-for="tab in tabs.tabs"
                :key="tab.key"
                :value="tab.key"
                class="-mb-px flex items-center gap-1.5 border-b-2 border-transparent px-3 py-2 text-sm text-muted-foreground transition-colors hover:text-foreground data-[state=active]:border-primary data-[state=active]:text-foreground"
            >
                <component
                    :is="resolveIcon(tab.icon)"
                    v-if="resolveIcon(tab.icon)"
                    class="size-4"
                />
                {{ tab.label }}
                <span
                    v-if="tab.badge"
                    class="rounded-full bg-muted px-1.5 text-xs text-muted-foreground"
                >
                    {{ tab.badge }}
                </span>
                <!--
                    An icon and a sentence, not only a red dot. Somebody who
                    cannot tell the dot from the badge beside it has no way to
                    know which tab is the one holding the problem.
                -->
                <span
                    v-if="hasError(tab.fields)"
                    class="flex items-center text-destructive"
                >
                    <CircleAlert class="size-3.5" />
                    <span class="sr-only">
                        {{ t('forms.tab_has_errors') }}
                    </span>
                </span>
            </TabsTrigger>
        </TabsList>

        <TabsContent
            v-for="tab in tabs.tabs"
            :key="tab.key"
            :value="tab.key"
            class="flex flex-col gap-4 focus-visible:outline-none"
        >
            <FormComponentRenderer
                v-for="(node, index) in tab.schema"
                :key="index"
                :node="node"
                :values="values"
                :errors="errors"
                @change="(name, value) => emit('change', name, value)"
            />
        </TabsContent>
    </TabsRoot>
</template>
