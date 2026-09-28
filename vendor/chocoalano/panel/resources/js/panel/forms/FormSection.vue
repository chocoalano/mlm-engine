<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { ref } from 'vue';
import FormGrid from '@/panel/forms/FormGrid.vue';
import type {
    FormValue,
    FormValues,
    SectionDefinition,
} from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = defineProps<{
    section: SectionDefinition;
    values: FormValues;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{ change: [name: string, value: FormValue] }>();

const open = ref(true);

function toggle(): void {
    if (props.section.collapsible) {
        open.value = !open.value;
    }
}
</script>

<template>
    <section class="rounded-lg border">
        <header
            class="flex items-start justify-between gap-3 border-b px-4 py-3"
        >
            <div class="flex flex-col gap-0.5">
                <h2 class="text-sm font-medium">{{ section.heading }}</h2>
                <p
                    v-if="section.description"
                    class="text-xs text-muted-foreground"
                >
                    {{ section.description }}
                </p>
            </div>
            <button
                v-if="section.collapsible"
                type="button"
                class="rounded-sm p-1 text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :aria-expanded="open"
                :aria-label="
                    open
                        ? t('forms.collapse_section')
                        : t('forms.expand_section')
                "
                @click="toggle"
            >
                <ChevronRight
                    class="size-4 transition-transform"
                    :class="{ 'rotate-90': open }"
                />
            </button>
        </header>

        <div v-show="open" class="p-4">
            <FormGrid
                :grid="{
                    component: 'grid',
                    columns: section.columns,
                    schema: section.schema,
                }"
                :values="values"
                :errors="errors"
                @change="(name, value) => emit('change', name, value)"
            />
        </div>
    </section>
</template>
