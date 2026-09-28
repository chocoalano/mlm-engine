<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { PaginationMeta } from '@/panel/types/table';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = defineProps<{
    pagination: PaginationMeta;
    perPageOptions: number[];
}>();

const emit = defineEmits<{
    page: [page: number];
    perPage: [perPage: number];
}>();

const summary = computed(() =>
    props.pagination.total === 0
        ? t('tables.no_results')
        : t('tables.range', {
              from: props.pagination.from,
              to: props.pagination.to,
              total: props.pagination.total,
          }),
);

const canGoBack = computed(() => props.pagination.page > 1);
const canGoForward = computed(
    () => props.pagination.page < props.pagination.lastPage,
);
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-muted-foreground">{{ summary }}</p>

        <div class="flex items-center gap-2">
            <Select
                :model-value="String(pagination.perPage)"
                @update:model-value="(value) => emit('perPage', Number(value))"
            >
                <SelectTrigger
                    class="h-8 w-23"
                    :aria-label="t('tables.rows_per_page')"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in perPageOptions"
                        :key="option"
                        :value="String(option)"
                    >
                        {{ t('tables.per_page', { count: option }) }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Button
                variant="outline"
                size="icon-sm"
                :disabled="!canGoBack"
                :aria-label="t('tables.previous_page')"
                @click="emit('page', pagination.page - 1)"
            >
                <ChevronLeft />
            </Button>

            <span class="text-sm whitespace-nowrap text-muted-foreground">
                {{
                    t('tables.page_of', {
                        page: pagination.page,
                        pages: Math.max(pagination.lastPage, 1),
                    })
                }}
            </span>

            <Button
                variant="outline"
                size="icon-sm"
                :disabled="!canGoForward"
                :aria-label="t('tables.next_page')"
                @click="emit('page', pagination.page + 1)"
            >
                <ChevronRight />
            </Button>
        </div>
    </div>
</template>
