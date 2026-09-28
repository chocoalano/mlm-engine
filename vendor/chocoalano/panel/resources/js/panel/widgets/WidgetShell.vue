<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Activity } from '@lucide/vue';
import { onBeforeUnmount, onMounted } from 'vue';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';
import type { WidgetDefinition } from '@/panel/types/widget';
import WidgetFilters from '@/panel/widgets/WidgetFilters.vue';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * What every widget wears: its heading, its filters, and its refresh.
 *
 * Kept out of the four renderers so a stats widget and a chart widget cannot
 * grow different ideas of where a heading goes — and so adding something
 * every widget has means editing one file.
 *
 * Polling reloads the props the page gave it rather than asking for one
 * widget. A widget's data *is* a prop of the page it sits on; a second
 * endpoint that answered for one widget would have to re-resolve the page's
 * authorization, its filters, and its context to say anything true.
 */
const props = defineProps<{
    widget: WidgetDefinition;
    /** The props a poll reloads. The page knows them; the widget does not. */
    reloadProps: string[];
}>();

let timer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    const seconds = props.widget.polling;

    if (seconds === null || seconds <= 0) {
        return;
    }

    timer = setInterval(() => {
        // `only` so a poll costs the widgets and nothing else. A partial
        // reload already keeps the rest of the page's state, which is what
        // stops a refresh from throwing away a half-typed filter.
        router.reload({ only: props.reloadProps });
    }, seconds * 1000);
});

onBeforeUnmount(() => {
    if (timer !== null) {
        clearInterval(timer);
    }
});

const { hook } = usePanelStyling();
</script>

<template>
    <section
        class="relative flex flex-col gap-4 rounded-xl border border-border/70 bg-card/80 p-4 shadow-sm shadow-black/[0.025] transition-shadow hover:shadow-md hover:shadow-black/[0.04] sm:p-5"
        :class="hook('widget')"
    >
        <div
            v-if="widget.heading || widget.description || widget.filters"
            class="flex flex-wrap items-start justify-between gap-4"
        >
            <div v-if="widget.heading || widget.description" class="min-w-0">
                <div v-if="widget.heading" class="flex items-center gap-2">
                    <span class="size-1.5 rounded-full bg-primary" />
                    <p class="truncate text-sm font-semibold tracking-tight">
                        {{ widget.heading }}
                    </p>
                    <Activity
                        v-if="widget.polling && widget.polling > 0"
                        class="size-3.5 text-muted-foreground"
                        aria-hidden="true"
                    />
                </div>
                <p
                    v-if="widget.description"
                    class="mt-1 max-w-2xl text-xs leading-5 text-muted-foreground"
                >
                    {{ widget.description }}
                </p>
            </div>

            <WidgetFilters
                v-if="widget.filters"
                :form="widget.filters.form"
                :namespace="`widgets[${widget.id}]`"
                :in-modal="widget.filters.inModal"
                :title="widget.heading ?? t('widgets.filters')"
            />
        </div>

        <slot />
    </section>
</template>
