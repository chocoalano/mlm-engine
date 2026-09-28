<script setup lang="ts">
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { ref, watch } from 'vue';
import { resolveIcon } from '@/panel/icons/registry';
import InfolistNode from '@/panel/infolists/InfolistNode.vue';
import type { ActionDefinition } from '@/panel/types/action';
import type { InfolistTabsDefinition } from '@/panel/types/infolist';

/**
 * The read-only twin of `FormTabs`, and on the same primitive for the same
 * reason: the hand-written version had the ARIA attributes and none of the
 * keyboard behaviour, so arrow keys did nothing and every tab was a tab stop.
 *
 * The ids were also global — `infolist-tab-details` — so a record showing an
 * infolist tab set beside a form tab set could produce the same id twice.
 * Reka generates them per instance.
 */
const props = defineProps<{ tabs: InfolistTabsDefinition }>();

const emit = defineEmits<{ run: [action: ActionDefinition] }>();

const active = ref(props.tabs.tabs[0]?.key ?? '');

/**
 * Persisted in the URL when the schema asked for it, so a reload — or a link
 * somebody was sent — opens where it was left.
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
            </TabsTrigger>
        </TabsList>

        <TabsContent
            v-for="tab in tabs.tabs"
            :key="tab.key"
            :value="tab.key"
            class="flex flex-col gap-4 focus-visible:outline-none"
        >
            <InfolistNode
                v-for="(child, index) in tab.schema"
                :key="index"
                :node="child"
                :columns="tab.columns"
                @run="(action) => emit('run', action)"
            />
        </TabsContent>
    </TabsRoot>
</template>
