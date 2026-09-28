<script setup lang="ts">
import { resolveIcon } from '@/panel/icons/registry';
import { ICON_CLASSES } from '@/panel/palette';
import type {
    PrimeIconDefinition,
    PrimeImageDefinition,
    PrimeTextDefinition,
} from '@/panel/types/form';

/**
 * The three components that only show something.
 *
 * A prime holds no value, validates nothing, and submits nothing — it is
 * content placed inside a form's layout so an explanation, a logo, or a
 * status marker can sit exactly where it belongs rather than above the whole
 * form. Grouped in one file because each is a handful of markup and keeping
 * them apart would be three files that say the same thing.
 */
defineProps<{
    node: PrimeTextDefinition | PrimeIconDefinition | PrimeImageDefinition;
}>();
</script>

<template>
    <p
        v-if="node.component === 'prime-text'"
        class="flex items-center gap-1.5"
        :class="[
            node.small ? 'text-xs' : 'text-sm',
            node.color ? ICON_CLASSES[node.color] : 'text-muted-foreground',
        ]"
    >
        <component
            :is="resolveIcon(node.icon)"
            v-if="resolveIcon(node.icon)"
            class="size-4 shrink-0"
        />
        {{ node.content }}
    </p>

    <span
        v-else-if="node.component === 'prime-icon'"
        class="inline-flex items-center gap-1.5 text-sm"
        :class="node.color ? ICON_CLASSES[node.color] : 'text-muted-foreground'"
    >
        <component
            :is="resolveIcon(node.icon)"
            v-if="resolveIcon(node.icon)"
            class="size-5"
            :aria-label="node.label ?? undefined"
        />
        <template v-if="node.label">{{ node.label }}</template>
    </span>

    <!--
        The URL is resolved on the server. A prime image with no URL renders
        nothing rather than a broken frame.
    -->
    <img
        v-else-if="node.url"
        :src="node.url"
        :alt="node.alt"
        :width="node.width ?? undefined"
        :class="node.rounded ? 'rounded-full object-cover' : 'rounded-md'"
    />
</template>
