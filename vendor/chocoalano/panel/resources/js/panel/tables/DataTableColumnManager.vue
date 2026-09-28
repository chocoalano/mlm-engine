<script setup lang="ts">
import { GripVertical, Settings2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { resolveIcon } from '@/panel/icons/registry';
import type { ColumnDefinition, TableDefinition } from '@/panel/types/table';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * Which columns are shown, and in what order.
 *
 * Both are server state like everything else about a table: the server
 * validates them, echoes back what it applied, and can remember them between
 * visits. This component only proposes changes — it never decides that a
 * column the table refused to make toggleable is now hidden.
 */
const props = defineProps<{
    table: TableDefinition;
    visible: string[];
    order: string[];
}>();

const emit = defineEmits<{
    change: [visible: string[], order: string[]];
    reset: [];
}>();

const manager = computed(() => props.table.columnManager);

const triggerIcon = computed(() => resolveIcon(manager.value.triggerIcon));

/**
 * Held while composing, for a manager that defers. Discarded whenever the
 * server answers: its answer is the truth.
 */
const draftVisible = ref<string[]>([...props.visible]);
const draftOrder = ref<string[]>([...props.order]);

watch(
    () => [props.visible, props.order],
    () => {
        draftVisible.value = [...props.visible];
        draftOrder.value = [...props.order];
    },
);

const definitionsByName = computed(
    () =>
        new Map<string, ColumnDefinition>(
            props.table.columns.map((column) => [column.name, column]),
        ),
);

const ordered = computed(() =>
    draftOrder.value
        .map((name) => definitionsByName.value.get(name))
        .filter((column): column is ColumnDefinition => column !== undefined),
);

function canHide(name: string): boolean {
    return manager.value.toggleable.includes(name);
}

const dragging = ref<string | null>(null);

function commit(): void {
    emit('change', draftVisible.value, draftOrder.value);
}

function toggle(name: string, checked: boolean): void {
    draftVisible.value = checked
        ? [...draftVisible.value, name]
        : draftVisible.value.filter((entry) => entry !== name);

    if (!manager.value.deferred) {
        commit();
    }
}

function onDrop(target: string): void {
    const source = dragging.value;

    dragging.value = null;

    if (source === null || source === target) {
        return;
    }

    const next = draftOrder.value.filter((name) => name !== source);
    const at = next.indexOf(target);

    next.splice(at < 0 ? next.length : at, 0, source);

    draftOrder.value = next;

    if (!manager.value.deferred) {
        commit();
    }
}

const hasChanges = computed(
    () =>
        JSON.stringify(draftVisible.value) !== JSON.stringify(props.visible) ||
        JSON.stringify(draftOrder.value) !== JSON.stringify(props.order),
);
</script>

<template>
    <!--
        A long column list is easier to work in with the page dimmed behind
        it, and dragging to reorder needs the room — so the shell is the
        server's decision. The body is identical either way.
    -->
    <Dialog v-if="manager.modal">
        <DialogTrigger as-child>
            <Button variant="outline" size="sm">
                <component :is="triggerIcon" v-if="triggerIcon" />
                <Settings2 v-else />
                {{ manager.triggerLabel }}
            </Button>
        </DialogTrigger>

        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ manager.triggerLabel }}</DialogTitle>
            </DialogHeader>

            <div class="flex max-h-96 flex-col gap-1 overflow-y-auto">
                <div
                    v-for="column in ordered"
                    :key="column.name"
                    class="flex items-center gap-2 rounded-md px-1 py-1 hover:bg-accent"
                    :class="dragging === column.name ? 'opacity-60' : undefined"
                    @dragover.prevent
                    @drop.prevent="onDrop(column.name)"
                >
                    <button
                        v-if="manager.reorderable"
                        type="button"
                        class="cursor-grab rounded-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:cursor-grabbing"
                        :aria-label="
                            t('tables.move_column', { column: column.label })
                        "
                        draggable="true"
                        @dragstart="dragging = column.name"
                    >
                        <GripVertical class="size-4" />
                    </button>

                    <Checkbox
                        :id="`column-modal-${column.name}`"
                        :model-value="draftVisible.includes(column.name)"
                        :disabled="!canHide(column.name)"
                        @update:model-value="
                            (checked) => toggle(column.name, checked === true)
                        "
                    />
                    <Label
                        :for="`column-modal-${column.name}`"
                        class="flex-1 font-normal"
                        :class="
                            canHide(column.name)
                                ? undefined
                                : 'text-muted-foreground'
                        "
                    >
                        {{ column.label }}
                    </Label>
                </div>
            </div>

            <div class="flex items-center justify-between gap-2 border-t pt-3">
                <Button
                    v-if="manager.showReset"
                    variant="ghost"
                    size="sm"
                    @click="emit('reset')"
                >
                    {{ manager.resetLabel }}
                </Button>
                <Button
                    v-if="manager.deferred"
                    size="sm"
                    class="ml-auto"
                    :disabled="!hasChanges"
                    @click="commit"
                >
                    {{ t('tables.apply') }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>

    <Popover v-else>
        <PopoverTrigger as-child>
            <Button variant="outline" size="sm">
                <component :is="triggerIcon" v-if="triggerIcon" />
                <Settings2 v-else />
                {{ manager.triggerLabel }}
            </Button>
        </PopoverTrigger>

        <PopoverContent class="w-64 p-0" align="end">
            <div class="flex max-h-80 flex-col gap-1 overflow-y-auto p-2">
                <div
                    v-for="column in ordered"
                    :key="column.name"
                    class="flex items-center gap-2 rounded-md px-1 py-1 hover:bg-accent"
                    :class="dragging === column.name ? 'opacity-60' : undefined"
                    @dragover.prevent
                    @drop.prevent="onDrop(column.name)"
                >
                    <button
                        v-if="manager.reorderable"
                        type="button"
                        class="cursor-grab rounded-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:cursor-grabbing"
                        :aria-label="
                            t('tables.move_column', { column: column.label })
                        "
                        draggable="true"
                        @dragstart="dragging = column.name"
                    >
                        <GripVertical class="size-4" />
                    </button>

                    <Checkbox
                        :id="`column-${column.name}`"
                        :model-value="
                            visible.includes(column.name) ||
                            draftVisible.includes(column.name)
                        "
                        :disabled="!canHide(column.name)"
                        @update:model-value="
                            (checked) => toggle(column.name, checked === true)
                        "
                    />
                    <Label
                        :for="`column-${column.name}`"
                        class="flex-1 font-normal"
                        :class="
                            canHide(column.name)
                                ? undefined
                                : 'text-muted-foreground'
                        "
                    >
                        {{ column.label }}
                    </Label>
                </div>
            </div>

            <div
                v-if="manager.deferred || manager.showReset"
                class="flex items-center justify-between gap-2 border-t p-2"
            >
                <Button
                    v-if="manager.showReset"
                    variant="ghost"
                    size="sm"
                    @click="emit('reset')"
                >
                    {{ manager.resetLabel }}
                </Button>
                <Button
                    v-if="manager.deferred"
                    size="sm"
                    class="ml-auto"
                    :disabled="!hasChanges"
                    @click="commit"
                >
                    {{ t('tables.apply') }}
                </Button>
            </div>
        </PopoverContent>
    </Popover>
</template>
