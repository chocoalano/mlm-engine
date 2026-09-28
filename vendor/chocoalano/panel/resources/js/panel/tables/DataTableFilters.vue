<script setup lang="ts">
import { computed } from 'vue';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import PanelDatePicker from '@/panel/components/PanelDatePicker.vue';
import FormComponentRenderer from '@/panel/forms/FormComponentRenderer.vue';
import DataTableQueryBuilder from '@/panel/tables/DataTableQueryBuilder.vue';
import type { FormValues } from '@/panel/types/form';
import type {
    DateFilterValue,
    FilterDefinition,
    FilterValue,
    FormFilterValue,
    QueryBuilderRule,
    TableState,
} from '@/panel/types/table';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = defineProps<{
    filters: FilterDefinition[];
    state: TableState;
    /**
     * Every column the table declares, so the query builder can tell a
     * constraint that belongs to a column from one that does not.
     */
    columnNames?: string[];
    /**
     * What the controls show. The same as the applied state for a live table;
     * the state plus whatever is being composed for a deferred one.
     */
    values: Record<string, FilterValue>;
}>();

const emit = defineEmits<{
    change: [name: string, value: FilterValue | null];
}>();

const CLEARED = '__all__';

function stringValue(name: string): string {
    const value = props.values[name];

    return typeof value === 'string' ? value : CLEARED;
}

/**
 * Each accessor narrows rather than asserts: filter values cross the wire as
 * untyped JSON, and a shape that does not match must render an empty control
 * rather than throwing inside the bar.
 */
function dateValue(name: string): DateFilterValue {
    const value = props.values[name];

    return typeof value === 'object' && value !== null && !Array.isArray(value)
        ? (value as DateFilterValue)
        : {};
}

function formValue(name: string): FormFilterValue {
    const value = props.values[name];

    return typeof value === 'object' && value !== null && !Array.isArray(value)
        ? (value as FormFilterValue)
        : {};
}

function ruleValue(name: string): QueryBuilderRule[] {
    const value = props.values[name];

    return Array.isArray(value) ? value : [];
}

function onForm(name: string, field: string, value: unknown): void {
    const next: FormFilterValue = {
        ...formValue(name),
        [field]:
            typeof value === 'string' ||
            typeof value === 'number' ||
            typeof value === 'boolean'
                ? value
                : null,
    };

    const filled = Object.values(next).some(
        (entry) => entry !== null && entry !== '',
    );

    emit('change', name, filled ? next : null);
}

function onRules(name: string, rules: QueryBuilderRule[]): void {
    emit('change', name, rules.length > 0 ? rules : null);
}

const hasFilters = computed(() => props.filters.length > 0);

/**
 * A ternary's third state is an answer the table means, not an empty control,
 * so it gets to name itself.
 */
function blankLabelFor(filter: FilterDefinition): string {
    if (filter.type === 'ternary') {
        return filter.blankLabel;
    }

    return filter.type === 'select'
        ? (filter.placeholder ?? t('tables.all'))
        : t('tables.all');
}

function onSelect(name: string, value: string): void {
    emit('change', name, value === CLEARED ? null : value);
}

/**
 * One bound of a range. `null` is the picker being cleared, which removes that
 * bound rather than setting it to an empty string: the server reads an absent
 * bound as open-ended, and `{from: ''}` would travel as a key that says
 * nothing. Clearing both is clearing the filter.
 */
function onDate(
    name: string,
    bound: 'from' | 'to',
    value: string | null,
): void {
    const next: DateFilterValue = { ...dateValue(name) };

    if (value === null || value === '') {
        delete next[bound];
    } else {
        next[bound] = value;
    }

    if (!next.from && !next.to) {
        emit('change', name, null);

        return;
    }

    emit('change', name, next);
}
</script>

<template>
    <div v-if="hasFilters" class="flex flex-wrap items-end gap-3">
        <template v-for="filter in filters" :key="filter.name">
            <div
                v-if="
                    filter.type === 'select' ||
                    filter.type === 'boolean' ||
                    filter.type === 'ternary'
                "
                class="flex flex-col gap-1"
            >
                <Label class="text-xs text-muted-foreground">
                    {{ filter.label }}
                </Label>
                <Select
                    :model-value="stringValue(filter.name)"
                    @update:model-value="
                        (value) => onSelect(filter.name, String(value))
                    "
                >
                    <SelectTrigger class="h-8 w-45">
                        <SelectValue :placeholder="blankLabelFor(filter)" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="CLEARED">
                            {{ blankLabelFor(filter) }}
                        </SelectItem>
                        <SelectItem
                            v-for="option in filter.options"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div v-else-if="filter.type === 'form'" class="flex flex-col gap-1">
                <Label class="text-xs text-muted-foreground">
                    {{ filter.label }}
                </Label>
                <!--
                    The same renderer a resource form uses, so a field looks
                    and validates identically wherever it appears.
                -->
                <div class="flex flex-wrap items-end gap-2">
                    <FormComponentRenderer
                        v-for="(node, index) in filter.form.schema"
                        :key="index"
                        :node="node"
                        :values="formValue(filter.name) as FormValues"
                        :errors="{}"
                        @change="
                            (field, value) => onForm(filter.name, field, value)
                        "
                    />
                </div>
            </div>

            <div
                v-else-if="filter.type === 'query_builder'"
                class="flex w-full flex-col gap-1"
            >
                <Label class="text-xs text-muted-foreground">
                    {{ filter.label }}
                </Label>
                <DataTableQueryBuilder
                    :filter="filter"
                    :rules="ruleValue(filter.name)"
                    :column-names="columnNames ?? []"
                    :visible-columns="state.columns.visible"
                    @change="(rules) => onRules(filter.name, rules)"
                />
            </div>

            <div v-else class="flex flex-col gap-1">
                <Label class="text-xs text-muted-foreground">
                    {{ filter.label }}
                </Label>
                <div class="flex items-center gap-2">
                    <PanelDatePicker
                        class="w-37.5"
                        :placeholder="t('tables.from')"
                        :aria-label="
                            t('tables.filter_from', { filter: filter.label })
                        "
                        :model-value="dateValue(filter.name).from ?? null"
                        :max="dateValue(filter.name).to ?? null"
                        @update:model-value="
                            (value) => onDate(filter.name, 'from', value)
                        "
                    />
                    <span class="text-muted-foreground">–</span>
                    <PanelDatePicker
                        class="w-37.5"
                        :placeholder="t('tables.to')"
                        :aria-label="
                            t('tables.filter_to', { filter: filter.label })
                        "
                        :model-value="dateValue(filter.name).to ?? null"
                        :min="dateValue(filter.name).from ?? null"
                        @update:model-value="
                            (value) => onDate(filter.name, 'to', value)
                        "
                    />
                </div>
            </div>
        </template>
    </div>
</template>
