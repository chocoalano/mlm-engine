<script setup lang="ts">
import { ChevronDown, ChevronUp, GripVertical } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * Moving a row, with a hand or without one.
 *
 * Reordering was a drag handle and nothing else: `draggable`, `dragstart`,
 * `drop`. That is not an accessibility gap at the edges — it is the entire
 * feature being unavailable to anyone who cannot drag, which includes keyboard
 * users, most switch users, and anyone on a device where a precise drag is
 * awkward.
 *
 * The handle stays exactly as it was, so pointer reordering is untouched. It
 * is also now a menu trigger, so the same two operations are reachable by
 * keyboard through Reka's `DropdownMenu` — which brings arrows, Escape,
 * typeahead and focus return with it.
 *
 * Both paths end in the same place: the parent turns either into one
 * `reorder` emit carrying the whole new order. There is no second transport.
 */
const props = defineProps<{
    /** What this row is called, for the control's accessible name. */
    label: string;
    /** 1-based, as a person counts. */
    position: number;
    total: number;
    /**
     * Marked on the trigger rather than left to attribute fall-through: the
     * root here is Reka's `DropdownMenu`, which renders no element of its own,
     * so a stray attribute would land nowhere. The table finds this to move
     * focus with the record after a reorder.
     */
    rowKey: string | number;
}>();

const emit = defineEmits<{
    /** −1 for up, +1 for down. */
    move: [offset: number];
    dragstart: [];
}>();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger
            :data-reorder-row="props.rowKey"
            class="cursor-grab rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:cursor-grabbing"
            :aria-label="
                t('tables.reorder_row', {
                    record: props.label,
                    position: props.position,
                    total: props.total,
                })
            "
            draggable="true"
            @dragstart="emit('dragstart')"
        >
            <GripVertical class="size-4" />
        </DropdownMenuTrigger>

        <DropdownMenuContent align="start">
            <!--
                Absent rather than present-and-disabled at the boundaries: a
                disabled item is still an item to arrow past, and "move the
                first row up" is not a thing that can be done rather than a
                thing that is temporarily unavailable.
            -->
            <DropdownMenuItem
                v-if="props.position > 1"
                @select="emit('move', -1)"
            >
                <ChevronUp class="size-4" />
                {{ t('tables.move_up') }}
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="props.position < props.total"
                @select="emit('move', 1)"
            >
                <ChevronDown class="size-4" />
                {{ t('tables.move_down') }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
