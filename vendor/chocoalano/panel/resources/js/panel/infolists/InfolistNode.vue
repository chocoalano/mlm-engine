<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import ActionButton from '@/panel/actions/ActionButton.vue';
import { gridClass, spanClass } from '@/panel/lib/grid';
import InfolistEntry from '@/panel/infolists/InfolistEntry.vue';
import InfolistTabs from '@/panel/infolists/InfolistTabs.vue';
import type { ActionDefinition } from '@/panel/types/action';
import type { InfolistComponentDefinition } from '@/panel/types/infolist';

/**
 * Renders one node of an infolist.
 *
 * Layouts recurse back through here, so nesting depth is a data concern
 * rather than a component concern — the same shape the form renderer has, and
 * for the same reason.
 *
 * Column classes map through literal records: `col-span-${n}` built by
 * interpolation would not exist in the bundle.
 */
defineProps<{
    node: InfolistComponentDefinition;
    /** The columns of the layout this node sits in, for its span. */
    columns: number;
}>();

const emit = defineEmits<{ run: [action: ActionDefinition] }>();

function columnsClass(columns: number): string {
    return gridClass(columns);
}

/** A layout takes the whole row; an entry takes what it asked for. */
function nodeSpanClass(
    child: InfolistComponentDefinition,
    columns: number,
): string {
    return spanClass(
        child.component === 'entry' ? child.columnSpan : 'full',
        columns,
    );
}
</script>

<template>
    <InfolistEntry
        v-if="node.component === 'entry'"
        :entry="node"
        @run="(action) => emit('run', action)"
    />

    <Card v-else-if="node.component === 'section'" class="shadow-xs">
        <CardHeader>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <CardTitle class="text-base">{{ node.heading }}</CardTitle>
                    <p
                        v-if="node.description"
                        class="text-sm text-muted-foreground"
                    >
                        {{ node.description }}
                    </p>
                </div>
                <div v-if="node.headerActions.length > 0" class="flex gap-1">
                    <ActionButton
                        v-for="action in node.headerActions"
                        :key="action.name"
                        :action="action"
                        @run="(next) => emit('run', next)"
                    />
                </div>
            </div>
        </CardHeader>
        <CardContent>
            <dl class="grid gap-4" :class="columnsClass(node.columns)">
                <div
                    v-for="(child, index) in node.schema"
                    :key="index"
                    :class="nodeSpanClass(child, node.columns)"
                >
                    <InfolistNode
                        :node="child"
                        :columns="node.columns"
                        @run="(action) => emit('run', action)"
                    />
                </div>
            </dl>
        </CardContent>
    </Card>

    <dl
        v-else-if="node.component === 'grid'"
        class="grid gap-4"
        :class="columnsClass(node.columns)"
    >
        <div
            v-for="(child, index) in node.schema"
            :key="index"
            :class="nodeSpanClass(child, node.columns)"
        >
            <InfolistNode
                :node="child"
                :columns="node.columns"
                @run="(action) => emit('run', action)"
            />
        </div>
    </dl>

    <InfolistTabs v-else :tabs="node" @run="(action) => emit('run', action)" />
</template>
