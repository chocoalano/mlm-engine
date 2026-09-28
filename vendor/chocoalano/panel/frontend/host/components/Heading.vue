<script setup lang="ts">
/**
 * The starter kit's section heading. `variant="small"` is what the panel's
 * settings sections use.
 */
defineProps<{
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}>();
</script>

<template>
    <header>
        <h3>{{ title }}</h3>
        <p v-if="description">{{ description }}</p>
    </header>
</template>
