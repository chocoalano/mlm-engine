<script setup lang="ts">
import { resolveIcon } from '@/panel/icons/registry';
import type { EmptyStateDefinition } from '@/panel/types/form';

defineProps<{ state: EmptyStateDefinition }>();
</script>

<template>
    <!--
        Says why a part of the form has nothing in it. Purely content: it
        holds no fields and reads no values, which is what separates it from
        a section that happens to be empty.
    -->
    <div
        class="flex flex-col items-center gap-2 rounded-md border border-dashed p-8 text-center"
    >
        <component
            :is="resolveIcon(state.icon)"
            v-if="resolveIcon(state.icon)"
            class="size-8 text-muted-foreground"
        />
        <p class="text-sm font-medium">{{ state.heading }}</p>
        <p v-if="state.description" class="text-sm text-muted-foreground">
            {{ state.description }}
        </p>
    </div>
</template>
