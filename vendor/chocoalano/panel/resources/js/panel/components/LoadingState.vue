<script setup lang="ts">
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

withDefaults(
    defineProps<{
        variant?: 'rows' | 'cards';
        count?: number;
    }>(),
    {
        variant: 'rows',
        count: 4,
    },
);
</script>

<template>
    <div
        v-if="variant === 'cards'"
        class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
        aria-busy="true"
    >
        <!-- The skeleton mirrors the real card's shape, so nothing shifts
             when the deferred payload lands. -->
        <Card v-for="index in count" :key="index" class="gap-2 py-4 shadow-xs">
            <div class="flex flex-col gap-2 px-4">
                <Skeleton class="h-4 w-24" />
                <Skeleton class="h-8 w-16" />
                <Skeleton class="h-3 w-32" />
            </div>
        </Card>
    </div>

    <Card v-else class="gap-2 py-4 shadow-xs" aria-busy="true">
        <div class="flex flex-col gap-2 px-4">
            <Skeleton v-for="index in count" :key="index" class="h-10 w-full" />
        </div>
    </Card>
</template>
