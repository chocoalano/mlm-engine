<script setup lang="ts">
import { Check, X } from '@lucide/vue';
import { computed, defineAsyncComponent } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import type { CellEditValue } from '@/panel/composables/useActions';
import { resolveIcon } from '@/panel/icons/registry';
import { BADGE_CLASSES, ICON_CLASSES } from '@/panel/palette';
import { resolveColumnComponent } from '@/panel/tables/registry';
import {
    asBadgeCell,
    asBooleanCell,
    asColorCell,
    asDateCell,
    asEditableCell,
    asIconCell,
    asImageCell,
    asNumberCell,
    asTextCell,
} from '@/panel/types/cellGuards';
import type { CellValue, ColumnDefinition } from '@/panel/types/table';

const props = defineProps<{
    column: ColumnDefinition;
    value: CellValue;
    /** The key of the row this cell belongs to, for a write. */
    recordKey?: string | number;
    /**
     * Overrides the column's own `wrap` for one placement.
     *
     * A column declares whether its text wraps *in a table*, where a row of
     * wrapping cells is a row of different heights. A card's description slot
     * is the opposite case — it is a paragraph, and truncating it to one line
     * throws away the thing it was put there to say. The container knows
     * which it is; the column cannot.
     */
    wrap?: boolean;
}>();

const emit = defineEmits<{ edit: [column: string, value: CellEditValue] }>();

const text = computed(() => asTextCell(props.value));
const number = computed(() => asNumberCell(props.value));
const badge = computed(() => asBadgeCell(props.value));
const boolean = computed(() => asBooleanCell(props.value));
const date = computed(() => asDateCell(props.value));
const image = computed(() => asImageCell(props.value));
const icon = computed(() => asIconCell(props.value));
const editable = computed(() => asEditableCell(props.value));

/**
 * Sent as a proposal, never applied locally: the server validates the value,
 * authorizes the record, and re-checks whether this cell is writable for it,
 * then answers with the row as it now is.
 */
function onEdit(value: CellEditValue): void {
    emit('edit', props.column.name, value);
}
const color = computed(() => asColorCell(props.value));

/** One placeholder rule for every renderer, so an empty cell reads the same. */
const placeholder = computed(() => props.column.placeholder ?? '—');

const iconComponent = computed(() =>
    icon.value === null ? null : resolveIcon(icon.value.icon),
);

/**
 * Loaded on demand: a custom column is rare, and bundling every one of them
 * into the table renderer would cost every table that uses none.
 */
const customComponent = computed(() => {
    if (props.column.type !== 'custom') {
        return null;
    }

    const loader = resolveColumnComponent(props.column.component);

    return loader === null ? null : defineAsyncComponent(loader);
});
</script>

<template>
    <template v-if="column.type === 'text'">
        <span v-if="text" :class="(wrap ?? column.wrap) ? '' : 'truncate'">
            {{ text }}
        </span>
        <span v-else class="text-muted-foreground">{{ placeholder }}</span>
    </template>

    <template v-else-if="column.type === 'number'">
        <span v-if="number" class="tabular-nums">{{ number.display }}</span>
        <span v-else class="text-muted-foreground">{{ placeholder }}</span>
    </template>

    <template v-else-if="column.type === 'badge'">
        <Badge
            v-if="badge"
            variant="secondary"
            :class="BADGE_CLASSES[badge.color]"
        >
            {{ badge.label }}
        </Badge>
        <span v-else class="text-muted-foreground">{{ placeholder }}</span>
    </template>

    <template v-else-if="column.type === 'boolean'">
        <span class="inline-flex items-center gap-1.5">
            <Check v-if="boolean.value" class="size-4 text-success" />
            <X v-else class="size-4 text-muted-foreground" />
            <span class="sr-only">{{ boolean.label }}</span>
        </span>
    </template>

    <template v-else-if="column.type === 'date' || column.type === 'datetime'">
        <time v-if="date" :datetime="date.iso" class="whitespace-nowrap">
            {{ date.display }}
        </time>
        <span v-else class="text-muted-foreground">{{ placeholder }}</span>
    </template>

    <template v-else-if="column.type === 'image'">
        <Avatar :class="column.circular ? 'rounded-full' : 'rounded-md'">
            <AvatarImage v-if="image.url" :src="image.url" :alt="image.alt" />
            <AvatarFallback>{{ image.fallback }}</AvatarFallback>
        </Avatar>
    </template>

    <template v-else-if="column.type === 'icon'">
        <span v-if="icon" class="inline-flex items-center">
            <component
                :is="iconComponent"
                v-if="iconComponent"
                class="size-4"
                :class="ICON_CLASSES[icon.color]"
            />
            <!-- An icon alone reads as an empty cell to a screen reader. -->
            <span class="sr-only">{{ icon.label }}</span>
        </span>
        <span v-else class="text-muted-foreground">{{ placeholder }}</span>
    </template>

    <template v-else-if="column.type === 'color'">
        <span v-if="color" class="inline-flex items-center gap-2">
            <!--
                The value was validated as a CSS colour on the server before it
                was sent, which is what makes an inline background safe here.
            -->
            <span
                class="size-4 rounded border"
                :style="{ backgroundColor: color.color }"
            />
            <span v-if="column.copyable" class="font-mono text-xs">
                {{ color.label }}
            </span>
            <span v-else class="sr-only">{{ color.label }}</span>
        </span>
        <span v-else class="text-muted-foreground">{{ placeholder }}</span>
    </template>

    <template v-else-if="column.type === 'toggle'">
        <Switch
            v-if="editable"
            :model-value="editable.value === true"
            :disabled="editable.disabled"
            :aria-label="column.label"
            @update:model-value="(checked) => onEdit(checked)"
        />
    </template>

    <template v-else-if="column.type === 'checkbox'">
        <Checkbox
            v-if="editable"
            :model-value="editable.value === true"
            :disabled="editable.disabled"
            :aria-label="column.label"
            @update:model-value="(checked) => onEdit(checked === true)"
        />
    </template>

    <template v-else-if="column.type === 'text_input'">
        <Input
            v-if="editable"
            class="h-8"
            :type="column.inputType"
            :maxlength="column.maxLength ?? undefined"
            :disabled="editable.disabled"
            :aria-label="column.label"
            :model-value="String(editable.value)"
            @change="
                (event: Event) =>
                    onEdit((event.target as HTMLInputElement).value)
            "
        />
    </template>

    <template v-else-if="column.type === 'select'">
        <Select
            v-if="editable"
            :model-value="String(editable.value)"
            :disabled="editable.disabled"
            @update:model-value="(next) => onEdit(String(next))"
        >
            <SelectTrigger class="h-8" :aria-label="column.label">
                <SelectValue :placeholder="placeholder" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="option in column.options"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </SelectItem>
            </SelectContent>
        </Select>
    </template>

    <template v-else-if="column.type === 'custom'">
        <component
            :is="customComponent"
            v-if="customComponent"
            :state="value"
        />
        <span v-else class="text-muted-foreground">{{ placeholder }}</span>
    </template>
</template>
