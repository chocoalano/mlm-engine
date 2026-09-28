<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PanelDatePicker from '@/panel/components/PanelDatePicker.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    ConstraintDefinition,
    QueryBuilderFilterDefinition,
    QueryBuilderRule,
} from '@/panel/types/table';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * A list of conditions the user composes.
 *
 * Every choice offered here comes from the server's declaration — which
 * columns may be constrained, and which comparisons each one supports. The
 * component never invents a column or an operator; it renders the ones it
 * was given, and the server checks them again on arrival.
 *
 * Rules are ANDed. Nested and/or groups would need a recursive schema on both
 * sides and a UI to match; a flat list answers the question most tables are
 * actually asked.
 */
const props = withDefaults(
    defineProps<{
        filter: QueryBuilderFilterDefinition;
        rules: QueryBuilderRule[];
        /**
         * Every column the table declares, visible or not.
         *
         * What separates a constraint that belongs to a column from one the
         * developer declared on its own: the second kind has no column to be
         * hidden, so it is always offered.
         */
        columnNames?: string[];
        /** The columns on screen right now, from the table's own state. */
        visibleColumns?: string[];
    }>(),
    { columnNames: () => [], visibleColumns: () => [] },
);

const emit = defineEmits<{ change: [rules: QueryBuilderRule[]] }>();

const constraintsByName = computed(
    () =>
        new Map<string, ConstraintDefinition>(
            props.filter.constraints.map((constraint) => [
                constraint.name,
                constraint,
            ]),
        ),
);

function constraintFor(rule: QueryBuilderRule): ConstraintDefinition | null {
    return constraintsByName.value.get(rule.column) ?? null;
}

function needsValue(rule: QueryBuilderRule): boolean {
    const constraint = constraintFor(rule);

    return (
        constraint?.operators.find(
            (operator) => operator.value === rule.operator,
        )?.needsValue ?? false
    );
}

/**
 * Which control the value is entered with.
 *
 * The server's `input` is a *semantic* type — `DateConstraint` declares
 * `date` because the value is a date, not because a date input should be
 * rendered. Passing it straight to an `<input type>` is how a native browser
 * date picker ended up in the query builder without a literal `type="date"`
 * anywhere to grep for.
 *
 * `none` cannot reach here: an operator that needs no value renders no
 * control. It is mapped anyway so the function is total.
 */
function inputTypeFor(rule: QueryBuilderRule): string {
    const input = constraintFor(rule)?.input ?? 'text';

    return input === 'none' ? 'text' : input;
}

/** A date value is picked from the panel's calendar, like every other date. */
function isDateRule(rule: QueryBuilderRule): boolean {
    return inputTypeFor(rule) === 'date';
}

/** The picker speaks `string | null`; a rule may hold a number or be absent. */
function dateValueOf(rule: QueryBuilderRule): string | null {
    return typeof rule.value === 'string' && rule.value !== ''
        ? rule.value
        : null;
}

/**
 * Which conditions can be *chosen* right now.
 *
 * A table's columns are the things its reader can see, so they are the things
 * it makes sense to filter by — hiding a column takes it out of this list.
 *
 * A constraint with no column of that name is left alone: the developer
 * declared it deliberately, there is nothing on screen to hide, and dropping
 * it would break every table written before columns described themselves.
 */
const selectableConstraints = computed(() => {
    const columns = new Set(props.columnNames);
    const visible = new Set(props.visibleColumns);

    return props.filter.constraints.filter(
        (constraint) =>
            !columns.has(constraint.name) || visible.has(constraint.name),
    );
});

/**
 * What one rule's column select offers.
 *
 * Its own column is always among them, even after that column is hidden.
 * Hiding a column changes what can be *added*; it does not silently rewrite a
 * filter the user already built, and a select that could not show its own
 * value would render blank and lose the rule on the next change.
 */
function constraintsFor(rule: QueryBuilderRule): ConstraintDefinition[] {
    const selectable = selectableConstraints.value;

    if (selectable.some((constraint) => constraint.name === rule.column)) {
        return selectable;
    }

    const own = constraintsByName.value.get(rule.column);

    return own === undefined ? selectable : [own, ...selectable];
}

const canAdd = computed(
    () =>
        selectableConstraints.value.length > 0 &&
        props.rules.length < props.filter.maxRules,
);

/**
 * Whether there is nothing left to filter by.
 *
 * Distinguished from "the button is disabled because the rule limit is
 * reached": a reader who has hidden every queryable column should be told
 * that, not shown a control that does nothing.
 */
const noColumns = computed(
    () => selectableConstraints.value.length === 0 && props.rules.length === 0,
);

function addRule(): void {
    const constraint = selectableConstraints.value[0];

    if (constraint === undefined) {
        return;
    }

    emit('change', [
        ...props.rules,
        {
            column: constraint.name,
            operator: constraint.operators[0]?.value ?? '',
            value: null,
        },
    ]);
}

function updateRule(index: number, patch: Partial<QueryBuilderRule>): void {
    emit(
        'change',
        props.rules.map((rule, position) =>
            position === index ? { ...rule, ...patch } : rule,
        ),
    );
}

/**
 * Changing the column resets the operator: the comparisons a text column
 * offers are not the ones a boolean does, and keeping the old one would leave
 * a rule the server drops without saying why.
 */
function onColumn(index: number, column: string): void {
    const constraint = constraintsByName.value.get(column);

    updateRule(index, {
        column,
        operator: constraint?.operators[0]?.value ?? '',
        value: null,
    });
}

function removeRule(index: number): void {
    emit(
        'change',
        props.rules.filter((_, position) => position !== index),
    );
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div
            v-for="(rule, index) in rules"
            :key="index"
            class="flex flex-wrap items-center gap-2"
        >
            <Select
                :model-value="rule.column"
                @update:model-value="(value) => onColumn(index, String(value))"
            >
                <SelectTrigger class="h-8 w-40">
                    <SelectValue :placeholder="t('tables.column')" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="constraint in constraintsFor(rule)"
                        :key="constraint.name"
                        :value="constraint.name"
                    >
                        {{ constraint.label }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Select
                :model-value="rule.operator"
                @update:model-value="
                    (value) =>
                        updateRule(index, {
                            operator: String(value),
                            value: null,
                        })
                "
            >
                <SelectTrigger class="h-8 w-40">
                    <SelectValue :placeholder="t('tables.condition')" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="operator in constraintFor(rule)?.operators ?? []"
                        :key="operator.value"
                        :value="operator.value"
                    >
                        {{ operator.label }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <PanelDatePicker
                v-if="needsValue(rule) && isDateRule(rule)"
                class="w-44"
                :aria-label="
                    t('tables.rule_value', {
                        rule: constraintFor(rule)?.label ?? t('tables.rule'),
                    })
                "
                :model-value="dateValueOf(rule)"
                @update:model-value="(value) => updateRule(index, { value })"
            />

            <Input
                v-else-if="needsValue(rule)"
                class="h-8 w-44"
                :type="inputTypeFor(rule)"
                :aria-label="
                    t('tables.rule_value', {
                        rule: constraintFor(rule)?.label ?? t('tables.rule'),
                    })
                "
                :model-value="rule.value ?? ''"
                @update:model-value="
                    (value) => updateRule(index, { value: String(value) })
                "
            />

            <Button
                variant="ghost"
                size="icon-sm"
                :aria-label="t('tables.remove_rule', { number: index + 1 })"
                @click="removeRule(index)"
            >
                <X />
            </Button>
        </div>

        <div>
            <Button v-if="canAdd" variant="outline" size="sm" @click="addRule">
                <Plus />
                {{ t('tables.add_condition') }}
            </Button>
            <!--
                Three states, not two. A reader who has hidden every queryable
                column is told so rather than shown a dropdown with nothing in
                it, and the rule ceiling is a different sentence from having
                nothing to filter by.
            -->
            <p v-else-if="noColumns" class="text-xs text-muted-foreground">
                {{ t('tables.no_queryable_columns') }}
            </p>
            <p
                v-else-if="rules.length > 0"
                class="text-xs text-muted-foreground"
            >
                {{ t('tables.max_conditions', { count: filter.maxRules }) }}
            </p>
        </div>
    </div>
</template>
