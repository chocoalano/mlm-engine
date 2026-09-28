<script setup lang="ts">
import { Check, X } from '@lucide/vue';
import { computed, defineAsyncComponent } from 'vue';
import { Badge } from '@/components/ui/badge';
import ActionButton from '@/panel/actions/ActionButton.vue';
import { resolveFormComponent } from '@/panel/forms/registry';
import { resolveIcon } from '@/panel/icons/registry';
import InfolistNode from '@/panel/infolists/InfolistNode.vue';
import { BADGE_CLASSES, ICON_CLASSES } from '@/panel/palette';
import type { ActionDefinition } from '@/panel/types/action';
import type { EntryDefinition } from '@/panel/types/infolist';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * One entry. The switch is exhaustive by construction: a PHP entry type
 * without a branch here is a compile error rather than a blank cell.
 */
const props = defineProps<{
    entry: EntryDefinition;
}>();

const emit = defineEmits<{ run: [action: ActionDefinition] }>();

function assertNever(value: never): never {
    throw new Error(`Unhandled entry type: ${JSON.stringify(value)}`);
}

function ensureHandled(entry: EntryDefinition): void {
    switch (entry.type) {
        case 'text':
        case 'badge':
        case 'boolean':
        case 'datetime':
        case 'key-value':
        case 'icon':
        case 'image':
        case 'color':
        case 'code':
        case 'repeatable':
        case 'custom':
            return;
        default:
            assertNever(entry);
    }
}

ensureHandled(props.entry);

/**
 * Loaded on demand: a custom entry is rare, and bundling every one of them
 * would cost every view page that has none.
 */
const custom = computed(() => {
    if (props.entry.type !== 'custom') {
        return null;
    }

    const loader = resolveFormComponent(props.entry.componentName);

    return loader === null ? null : defineAsyncComponent(loader);
});

async function copy(value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
    } catch {
        // A refused clipboard is not worth an error: the value is on screen
        // and can be selected by hand.
    }
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <div class="flex items-center gap-2">
            <dt class="text-sm text-muted-foreground">{{ entry.label }}</dt>
            <ActionButton
                v-if="entry.action"
                :action="entry.action"
                size="icon-sm"
                @run="(action) => emit('run', action)"
            />
        </div>

        <dd class="text-sm">
            <template v-if="entry.type === 'text'">
                <p
                    v-if="entry.value"
                    :class="entry.prose ? 'text-pretty' : 'truncate'"
                >
                    {{ entry.value }}
                </p>
                <span v-else class="text-muted-foreground">
                    {{ entry.placeholder ?? '—' }}
                </span>
            </template>

            <template v-else-if="entry.type === 'badge'">
                <Badge
                    v-if="entry.value"
                    variant="secondary"
                    :class="BADGE_CLASSES[entry.value.color]"
                >
                    {{ entry.value.label }}
                </Badge>
                <span v-else class="text-muted-foreground">
                    {{ entry.placeholder ?? '—' }}
                </span>
            </template>

            <template v-else-if="entry.type === 'boolean'">
                <span class="flex items-center gap-1.5">
                    <Check v-if="entry.value" class="size-4 text-success" />
                    <X v-else class="size-4 text-muted-foreground" />
                    {{ entry.value ? entry.trueLabel : entry.falseLabel }}
                </span>
            </template>

            <template v-else-if="entry.type === 'datetime'">
                <span v-if="entry.value" class="tabular-nums">
                    {{ entry.value }}
                </span>
                <span v-else class="text-muted-foreground">
                    {{ entry.placeholder ?? '—' }}
                </span>
            </template>

            <template v-else-if="entry.type === 'icon'">
                <span
                    v-if="entry.value"
                    class="flex items-center gap-1.5"
                    :class="ICON_CLASSES[entry.value.color]"
                >
                    <component
                        :is="resolveIcon(entry.value.icon)"
                        v-if="resolveIcon(entry.value.icon)"
                        class="size-4"
                    />
                    {{ entry.value.label }}
                </span>
                <span v-else class="text-muted-foreground">
                    {{ entry.placeholder ?? '—' }}
                </span>
            </template>

            <template v-else-if="entry.type === 'image'">
                <img
                    v-if="entry.value"
                    :src="entry.value"
                    :alt="entry.label"
                    :width="entry.size"
                    :height="entry.size"
                    class="object-cover"
                    :class="entry.circular ? 'rounded-full' : 'rounded-md'"
                />
                <span v-else class="text-muted-foreground">
                    {{ entry.placeholder ?? '—' }}
                </span>
            </template>

            <template v-else-if="entry.type === 'color'">
                <span v-if="entry.value" class="flex items-center gap-2">
                    <span
                        class="size-4 rounded-full border border-input"
                        :style="{ backgroundColor: entry.value }"
                    />
                    <span class="font-mono">{{ entry.value }}</span>
                    <button
                        v-if="entry.copyable"
                        type="button"
                        class="text-xs text-muted-foreground underline underline-offset-4"
                        @click="copy(entry.value)"
                    >
                        {{ t('actions.copy') }}
                    </button>
                </span>
                <span v-else class="text-muted-foreground">
                    {{ entry.placeholder ?? '—' }}
                </span>
            </template>

            <template v-else-if="entry.type === 'code'">
                <div v-if="entry.value" class="flex flex-col gap-1">
                    <pre
                        class="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs"
                    ><code>{{ entry.value }}</code></pre>
                    <button
                        v-if="entry.copyable"
                        type="button"
                        class="w-fit text-xs text-muted-foreground underline underline-offset-4"
                        @click="copy(entry.value)"
                    >
                        {{ t('actions.copy') }}
                    </button>
                </div>
                <span v-else class="text-muted-foreground">
                    {{ entry.placeholder ?? '—' }}
                </span>
            </template>

            <template v-else-if="entry.type === 'key-value'">
                <dl v-if="entry.value.length > 0" class="flex flex-col gap-1">
                    <div
                        v-for="pair in entry.value"
                        :key="pair.key"
                        class="grid grid-cols-3 gap-2"
                    >
                        <dt class="truncate text-muted-foreground">
                            {{ pair.key }}
                        </dt>
                        <dd class="col-span-2 truncate">{{ pair.value }}</dd>
                    </div>
                </dl>
                <span v-else class="text-muted-foreground">
                    {{ entry.placeholder ?? '—' }}
                </span>
            </template>

            <!--
                A repeatable renders its own sub-schema once per item. The
                children are ordinary entries, which is why they go back
                through the same renderer rather than a second one.
            -->
            <template v-else-if="entry.type === 'repeatable'">
                <div v-if="entry.value.length > 0" class="flex flex-col gap-3">
                    <div
                        v-for="(item, index) in entry.value"
                        :key="index"
                        class="rounded-md border border-input p-3"
                    >
                        <p
                            v-if="item.label"
                            class="mb-2 text-xs font-medium text-muted-foreground"
                        >
                            {{ item.label }}
                        </p>
                        <dl class="grid gap-3">
                            <InfolistNode
                                v-for="(child, childIndex) in item.schema"
                                :key="childIndex"
                                :node="child"
                                :columns="entry.columns"
                                @run="(action) => emit('run', action)"
                            />
                        </dl>
                    </div>
                </div>
                <span v-else class="text-muted-foreground">
                    {{ entry.placeholder ?? '—' }}
                </span>
            </template>

            <template v-else>
                <component
                    :is="custom"
                    v-if="custom"
                    :entry="entry"
                    :value="entry.value"
                    :config="entry.config"
                />
                <span v-else class="text-muted-foreground">
                    {{ entry.placeholder ?? '—' }}
                </span>
            </template>
        </dd>

        <p v-if="entry.helperText" class="text-xs text-muted-foreground">
            {{ entry.helperText }}
        </p>
    </div>
</template>
