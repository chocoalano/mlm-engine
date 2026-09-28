<script setup lang="ts">
import FormGrid from '@/panel/forms/FormGrid.vue';
import type {
    FormValue,
    FormValues,
    RelationshipDefinition,
} from '@/panel/types/form';

/**
 * Fields belonging to a related record.
 *
 * Visually a titled group, like a section — the difference is entirely on the
 * server, where these fields are written to another row. Its children already
 * carry dotted names, so nothing here has to know about the namespace, and
 * the layout goes through the same grid every other container uses.
 */
defineProps<{
    group: RelationshipDefinition;
    values: FormValues;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{ change: [name: string, value: FormValue] }>();
</script>

<template>
    <section class="rounded-lg border">
        <header class="flex flex-col gap-0.5 border-b px-4 py-3">
            <h2 class="text-sm font-medium">{{ group.heading }}</h2>
            <p v-if="group.description" class="text-xs text-muted-foreground">
                {{ group.description }}
            </p>
        </header>

        <div class="p-4">
            <FormGrid
                :grid="{
                    component: 'grid',
                    columns: group.columns,
                    schema: group.schema,
                }"
                :values="values"
                :errors="errors"
                @change="(name, value) => emit('change', name, value)"
            />
        </div>
    </section>
</template>
