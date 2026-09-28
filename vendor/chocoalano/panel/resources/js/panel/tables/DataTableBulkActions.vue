<script setup lang="ts">
import { Button } from '@/components/ui/button';
import ActionButton from '@/panel/actions/ActionButton.vue';
import type { ActionDefinition } from '@/panel/types/action';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * Appears only while rows are selected. The count comes from the selection
 * itself, so it cannot claim more rows than were chosen.
 */
defineProps<{
    actions: ActionDefinition[];
    selected: Array<string | number>;
    processing: boolean;
}>();

const emit = defineEmits<{
    run: [action: ActionDefinition];
    clear: [];
}>();
</script>

<template>
    <!--
        Sticky to the bottom of the viewport rather than placed in the flow.

        Two reasons, and both matter in a long list. Selecting a row used to
        insert a block above the table and push every row down by its height,
        which moves the checkbox out from under the pointer mid-selection. And
        on a hundred-row page the actions were at the top, so ticking a row
        near the bottom meant scrolling back up to act on it.
    -->
    <div
        v-if="selected.length > 0 && actions.length > 0"
        class="panel-bulk-actions sticky bottom-4 z-20 flex flex-wrap items-center gap-2 rounded-lg border bg-card px-3 py-2 shadow-lg"
    >
        <p class="text-sm font-medium tabular-nums">
            {{ selected.length }}
            {{ selected.length === 1 ? 'row' : 'rows' }} selected
        </p>

        <div class="ml-auto flex items-center gap-2">
            <ActionButton
                v-for="action in actions"
                :key="action.name"
                :action="action"
                :disabled="processing"
                @run="emit('run', action)"
            />
            <Button variant="ghost" size="sm" @click="emit('clear')">
                {{ t('tables.clear') }}
            </Button>
        </div>
    </div>
</template>
