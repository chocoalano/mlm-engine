<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent } from '@/components/ui/card';
import ActionButton from '@/panel/actions/ActionButton.vue';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';
import { gridClass, spanClass } from '@/panel/lib/grid';
import InfolistNode from '@/panel/infolists/InfolistNode.vue';
import type { ActionDefinition } from '@/panel/types/action';
import type {
    EntryDefinition,
    InfolistComponentDefinition,
    InfolistDefinition,
} from '@/panel/types/infolist';

/**
 * Renders a record's infolist.
 *
 * Entries declared outside any layout are grouped into one card rather than
 * each getting a box of its own, so a flat infolist reads as one panel.
 * Everything else recurses through `InfolistNode`.
 */
const props = defineProps<{
    infolist: InfolistDefinition;
}>();

const emit = defineEmits<{ run: [action: ActionDefinition] }>();

function isEntry(node: InfolistComponentDefinition): node is EntryDefinition {
    return node.component === 'entry';
}

const looseEntries = computed<EntryDefinition[]>(() =>
    props.infolist.schema.filter(isEntry),
);

const layouts = computed<InfolistComponentDefinition[]>(() =>
    props.infolist.schema.filter((node) => !isEntry(node)),
);

function columnsClass(columns: number): string {
    return gridClass(columns);
}

const { hook } = usePanelStyling();
</script>

<template>
    <div class="flex flex-col gap-6" :class="hook('infolist')">
        <!--
            Operations for the record as a whole. Above the infolist rather
            than beside a value, because that is what they are about.
        -->
        <div v-if="infolist.actions.length > 0" class="flex flex-wrap gap-2">
            <ActionButton
                v-for="action in infolist.actions"
                :key="action.name"
                :action="action"
                @run="(next) => emit('run', next)"
            />
        </div>

        <Card v-if="looseEntries.length > 0" class="shadow-xs">
            <CardContent>
                <dl class="grid gap-4" :class="columnsClass(infolist.columns)">
                    <div
                        v-for="entry in looseEntries"
                        :key="entry.name"
                        :class="spanClass(entry.columnSpan, infolist.columns)"
                    >
                        <InfolistNode
                            :node="entry"
                            :columns="infolist.columns"
                            @run="(action) => emit('run', action)"
                        />
                    </div>
                </dl>
            </CardContent>
        </Card>

        <InfolistNode
            v-for="(node, index) in layouts"
            :key="index"
            :node="node"
            :columns="infolist.columns"
            @run="(action) => emit('run', action)"
        />
    </div>
</template>
