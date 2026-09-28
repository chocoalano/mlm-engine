<script setup lang="ts">
import { usePanelStyling } from '@/panel/composables/usePanelStyling';

defineProps<{
    heading: string;
    subheading?: string | null;
}>();

const { hook } = usePanelStyling();
</script>

<template>
    <div
        class="flex flex-wrap items-start justify-between gap-3"
        :class="hook('page-header')"
    >
        <!--
            `text-xl` rather than `text-2xl`, and a tighter gap. The breadcrumb
            above already says where the user is, so the heading is a label
            rather than a title — and every pixel it does not take is a row of
            data on screen, which is the trade an ERP screen wants.
        -->
        <div class="flex min-w-0 flex-col gap-0.5">
            <!--
                It was `truncate`, unconditionally: a long record title was cut
                off at every width so the actions could stay on one line. That
                is the wrong way round — the title says which record is being
                looked at, and the actions can wrap. `text-balance` with
                `break-words` keeps a long word from overflowing rather than
                hiding the sentence.
            -->
            <h1
                class="text-xl font-semibold tracking-tight text-balance break-words"
            >
                {{ heading }}
            </h1>
            <p v-if="subheading" class="text-sm text-muted-foreground">
                {{ subheading }}
            </p>
        </div>

        <!--
            Header actions render here once the action system defines its
            serialized shape. Until then the slot stays empty rather than
            showing a button that does nothing.
        -->
        <div class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
