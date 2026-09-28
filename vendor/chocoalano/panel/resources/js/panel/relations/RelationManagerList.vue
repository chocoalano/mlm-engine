<script setup lang="ts">
import RelationManagerPanel from '@/panel/relations/RelationManagerPanel.vue';
import type { RelationDefinition } from '@/panel/types/relation';

/**
 * Every relation manager a record page carries.
 *
 * Renders nothing at all when there are none, so a resource without relation
 * managers looks exactly as it did before they existed — no heading, no
 * empty card, no gap.
 */
defineProps<{
    relations: RelationDefinition[];
    resource: string;
    record: string | number;
}>();
</script>

<template>
    <div v-if="relations.length > 0" class="flex flex-col gap-6">
        <RelationManagerPanel
            v-for="relation in relations"
            :key="relation.key"
            :relation="relation"
            :resource="resource"
            :record="record"
        />
    </div>
</template>
