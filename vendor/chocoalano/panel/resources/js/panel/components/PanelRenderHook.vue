<script setup lang="ts">
import { computed, defineAsyncComponent } from 'vue';
import { usePanel } from '@/panel/composables/usePanel';
import { usePanelPage } from '@/panel/composables/usePanelPage';
import { resolveHookComponent } from '@/panel/hooks/registry';
import type { PanelRenderHookName } from '@/panel/types/panel';

/**
 * Renders whatever the panel injected at this point in the shell.
 *
 * Nothing is the normal case: a panel that registered no hook here renders
 * an empty fragment, and so does one that named a component the registry
 * does not hold. A decorative injection must not be able to break the page
 * it decorates.
 */
const props = defineProps<{
    name: PanelRenderHookName;
}>();

const { panel } = usePanel();
const pageMetadata = usePanelPage();

/**
 * Scoping is filtered here rather than on the server because shared props
 * are built before the request reaches a page — the shell knows which page
 * it is rendering, the middleware does not. An empty scope list means every
 * page.
 */
const entries = computed(() => {
    const scope = pageMetadata.value?.scope ?? null;

    return (panel.value?.renderHooks?.[props.name] ?? []).filter(
        (entry) =>
            entry.scopes.length === 0 || entry.scopes.includes(scope ?? ''),
    );
});

function componentFor(name: string) {
    const loader = resolveHookComponent(name);

    return loader ? defineAsyncComponent(loader) : null;
}
</script>

<template>
    <template
        v-for="(entry, index) in entries"
        :key="`${entry.component}-${index}`"
    >
        <component
            :is="componentFor(entry.component)"
            v-if="componentFor(entry.component)"
            v-bind="entry.data"
        />
    </template>
</template>
