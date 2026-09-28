<script setup lang="ts">
import { computed } from 'vue';
import { resolveIcon } from '@/panel/icons/registry';

const props = defineProps<{
    heading: string;
    description?: string | null;
    icon?: string | null;
}>();

const resolved = computed(() => resolveIcon(props.icon));
</script>

<template>
    <div
        class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed px-6 py-12 text-center"
    >
        <component
            :is="resolved"
            v-if="resolved"
            class="size-6 text-muted-foreground"
        />
        <p class="text-sm font-medium">{{ heading }}</p>
        <p
            v-if="description"
            class="max-w-md text-sm text-balance text-muted-foreground"
        >
            {{ description }}
        </p>
        <div class="mt-2 flex items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
