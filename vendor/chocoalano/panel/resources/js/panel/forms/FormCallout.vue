<script setup lang="ts">
import FormComponentRenderer from '@/panel/forms/FormComponentRenderer.vue';
import { resolveIcon } from '@/panel/icons/registry';
import type {
    CalloutDefinition,
    CalloutTone,
    FormValue,
    FormValues,
} from '@/panel/types/form';

defineProps<{
    callout: CalloutDefinition;
    values: FormValues;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{ change: [name: string, value: FormValue] }>();

/**
 * Tones map to literal classes. An interpolated `border-${tone}-300` would
 * compile to nothing, which is why the server's tone is a closed enum.
 */
const TONE_CLASSES: Record<CalloutTone, string> = {
    info: 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-200',
    success:
        'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
    warning:
        'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200',
    danger: 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-200',
};
</script>

<template>
    <div class="rounded-md border p-4" :class="TONE_CLASSES[callout.tone]">
        <div class="flex gap-3">
            <component
                :is="resolveIcon(callout.icon)"
                v-if="resolveIcon(callout.icon)"
                class="mt-0.5 size-5 shrink-0"
            />

            <div class="flex w-full flex-col gap-2">
                <p v-if="callout.heading" class="text-sm font-medium">
                    {{ callout.heading }}
                </p>
                <p v-if="callout.body" class="text-sm">{{ callout.body }}</p>

                <!--
                    A callout may hold fields, so an explanation and the input
                    it explains stay together instead of being two components
                    that happen to sit next to each other.
                -->
                <div
                    v-if="callout.schema.length > 0"
                    class="flex flex-col gap-4 pt-1"
                >
                    <FormComponentRenderer
                        v-for="(node, index) in callout.schema"
                        :key="index"
                        :node="node"
                        :values="values"
                        :errors="errors"
                        @change="(name, value) => emit('change', name, value)"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
