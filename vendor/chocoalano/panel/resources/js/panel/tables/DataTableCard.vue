<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import {
    Card,
    CardAction,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import ActionGroup from '@/panel/actions/ActionGroup.vue';
import type { CellEditValue } from '@/panel/composables/useActions';
import type { ResolvedCardFace } from '@/panel/tables/cardFace';
import DataTableCell from '@/panel/tables/DataTableCell.vue';
import { ALIGNMENT_CLASSES, cellUrl } from '@/panel/tables/tableCells';
import type { ActionDefinition } from '@/panel/types/action';
import type { ColumnDefinition, TableRow } from '@/panel/types/table';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * One record, drawn as a card.
 *
 * Every value goes through `DataTableCell`, the same renderer a row uses, so a
 * `BadgeColumn` keeps its colours and an `ImageColumn` its avatar without this
 * component knowing either exists. What it owns is the *arrangement*: which
 * slot a column landed in, and the width each slot is allowed.
 *
 * That last part is load-bearing. `DataTableCell` renders a fragment with no
 * wrapper of its own — which is exactly why it works in a table cell and here
 * — so `truncate` has nothing to truncate against unless the container gives
 * it one. Every slot below carries `min-w-0`, without which a long value stops
 * its flex parent from shrinking and the card overflows sideways.
 */
const props = defineProps<{
    face: ResolvedCardFace;
    row: TableRow;
    selectable: boolean;
    selected: boolean;
}>();

const emit = defineEmits<{
    select: [checked: boolean];
    runAction: [action: ActionDefinition];
    editCell: [column: string, value: CellEditValue];
}>();

function alignment(column: ColumnDefinition): string {
    return ALIGNMENT_CLASSES[column.alignment];
}

function url(column: ColumnDefinition): string | null {
    return cellUrl(props.row, column);
}

function action(column: ColumnDefinition): ActionDefinition | undefined {
    return props.row.cellMeta[column.name]?.action;
}
</script>

<template>
    <Card class="gap-3 py-4 shadow-xs">
        <CardHeader class="gap-1 px-4">
            <div class="flex min-w-0 items-start gap-3">
                <div v-if="face.image" class="shrink-0">
                    <DataTableCell
                        :column="face.image"
                        :value="row.cells[face.image.name]"
                    />
                </div>

                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <CardTitle v-if="face.title" class="min-w-0 text-base">
                        <!--
                            The heading follows the same link its cell would in
                            a row, so the card's most obvious click target is
                            the one the table already declared.
                        -->
                        <component
                            :is="url(face.title) ? 'a' : 'span'"
                            :href="url(face.title) ?? undefined"
                            class="block min-w-0"
                            :class="url(face.title) ? 'hover:underline' : ''"
                        >
                            <DataTableCell
                                :column="face.title"
                                :value="row.cells[face.title.name]"
                            />
                        </component>
                    </CardTitle>

                    <p
                        v-if="face.description"
                        class="min-w-0 text-sm text-muted-foreground"
                    >
                        <!-- A description is a paragraph, not a table cell. -->
                        <DataTableCell
                            :column="face.description"
                            :value="row.cells[face.description.name]"
                            :wrap="true"
                        />
                    </p>

                    <div
                        v-if="face.badges.length > 0"
                        class="flex min-w-0 flex-wrap items-center gap-1.5 pt-0.5"
                    >
                        <DataTableCell
                            v-for="badge in face.badges"
                            :key="badge.name"
                            :column="badge"
                            :value="row.cells[badge.name]"
                        />
                    </div>
                </div>
            </div>

            <CardAction v-if="selectable">
                <Checkbox
                    :model-value="selected"
                    :aria-label="
                        t('tables.select_row', { record: String(row.key) })
                    "
                    @update:model-value="
                        (checked) => emit('select', checked === true)
                    "
                />
            </CardAction>
        </CardHeader>

        <CardContent v-if="face.details.length > 0" class="px-4">
            <dl class="flex flex-col gap-1.5 text-sm">
                <div
                    v-for="detail in face.details"
                    :key="detail.name"
                    class="flex min-w-0 items-baseline justify-between gap-3"
                >
                    <dt class="min-w-0 shrink-0 text-muted-foreground">
                        {{ detail.label }}
                    </dt>
                    <dd class="min-w-0 text-end" :class="alignment(detail)">
                        <component
                            :is="url(detail) ? 'a' : 'div'"
                            :href="url(detail) ?? undefined"
                            class="min-w-0"
                            :class="url(detail) ? 'hover:underline' : ''"
                        >
                            <button
                                v-if="action(detail)"
                                type="button"
                                class="min-w-0 text-start hover:underline"
                                @click="emit('runAction', action(detail)!)"
                            >
                                <DataTableCell
                                    :column="detail"
                                    :value="row.cells[detail.name]"
                                    :record-key="row.key"
                                    @edit="
                                        (column, value) =>
                                            emit('editCell', column, value)
                                    "
                                />
                            </button>
                            <DataTableCell
                                v-else
                                :column="detail"
                                :value="row.cells[detail.name]"
                                :record-key="row.key"
                                @edit="
                                    (column, value) =>
                                        emit('editCell', column, value)
                                "
                            />
                        </component>
                    </dd>
                </div>
            </dl>
        </CardContent>

        <!--
            The footer is the record actions, which is why the card face has no
            footer slot: they are already resolved per row, with authorization
            applied, so an action the user may not run is simply absent.
        -->
        <CardFooter v-if="row.actions.length > 0" class="justify-end px-4">
            <ActionGroup
                :actions="row.actions"
                @run="(chosen) => emit('runAction', chosen)"
            />
        </CardFooter>
    </Card>
</template>
