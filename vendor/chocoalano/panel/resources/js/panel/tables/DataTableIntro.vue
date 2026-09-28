<script setup lang="ts">
import { useId } from 'vue';
import FormCallout from '@/panel/forms/FormCallout.vue';
import type { CalloutDefinition } from '@/panel/types/form';

/**
 * What a table says before its rows.
 *
 * A description is standing context — what the list covers, what it leaves
 * out, how current it is. A callout is a notice: something conditional or
 * time-bound that the reader should meet before the data rather than discover
 * from it. They render in that order because that is the order they are read
 * in, and both sit above the toolbar so the controls stay next to the rows
 * they act on.
 *
 * The callout is the panel's own `FormCallout`, not a table-shaped copy of
 * it. A warning above a table and a warning inside a form section are the
 * same thing to a reader, and one component is one place to keep the tones,
 * the icons and the semantics honest.
 */
const props = defineProps<{
    description: string | null;
    callouts: CalloutDefinition[];
}>();

/**
 * Per instance, so two tables on one page describe themselves separately.
 * Vue's own counter rather than a module-level one — see the panel's field
 * identity for why that distinction matters inside a repeater.
 */
const uid = useId();

const descriptionId = `panel-table-description-${uid}`;

defineExpose({ descriptionId });

/** Nothing to say means nothing in the DOM, not an empty container. */
const hasIntro = (): boolean =>
    (props.description !== null && props.description !== '') ||
    props.callouts.length > 0;
</script>

<template>
    <div v-if="hasIntro()" class="flex flex-col gap-3">
        <p
            v-if="description"
            :id="descriptionId"
            class="text-sm text-muted-foreground"
        >
            {{ description }}
        </p>

        <FormCallout
            v-for="(callout, index) in callouts"
            :key="index"
            :callout="callout"
            :values="{}"
            :errors="{}"
        />
    </div>
</template>
