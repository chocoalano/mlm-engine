<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { RadioGroupItem, RadioGroupRoot } from 'reka-ui';
import { useAppearance } from '@/composables/useAppearance';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const { appearance, updateAppearance } = useAppearance();

/**
 * Light, Dark, or follow the system.
 *
 * Three plain buttons before this, with the selection expressed only as a
 * background colour. Nothing in the markup said which one was chosen, so
 * anyone not looking at it — a screen reader, a high-contrast mode that
 * flattens the background — was told there were three buttons and nothing
 * about the state of any of them.
 *
 * A radio group is what this is: one choice out of a closed set, exactly one
 * of which is always active. Reka's primitive carries `role="radiogroup"`,
 * `role="radio"` with `aria-checked`, arrow-key navigation and a roving
 * tabindex, so Tab enters the group once and the arrows move within it.
 *
 * Only the semantics changed. The appearance logic is still `useAppearance()`
 * — this component has never owned it and must not start, or the header
 * toggle and this control would drift apart.
 */
const options = [
    { value: 'light', Icon: Sun, key: 'ui.appearance_light' },
    { value: 'dark', Icon: Moon, key: 'ui.appearance_dark' },
    { value: 'system', Icon: Monitor, key: 'ui.appearance_system' },
] as const;
</script>

<template>
    <RadioGroupRoot
        :model-value="appearance"
        :aria-label="t('ui.appearance')"
        orientation="horizontal"
        class="bg-muted inline-flex gap-1 rounded-lg p-1"
        @update:model-value="
            (value) => updateAppearance(value as 'light' | 'dark' | 'system')
        "
    >
        <RadioGroupItem
            v-for="{ value, Icon, key } in options"
            :key="value"
            :value="value"
            :class="[
                'flex items-center rounded-md px-3.5 py-1.5 transition-colors',
                'focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none',
                appearance === value
                    ? 'bg-background text-foreground shadow-xs'
                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
            ]"
        >
            <component :is="Icon" class="-ml-1 h-4 w-4" />
            <span class="ml-1.5 text-sm">{{ t(key) }}</span>
        </RadioGroupItem>
    </RadioGroupRoot>
</template>
