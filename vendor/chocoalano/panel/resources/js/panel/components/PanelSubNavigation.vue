<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePanel } from '@/panel/composables/usePanel';
import { resolveIcon } from '@/panel/icons/registry';
import type {
    SubNavigationItem,
    SubNavigationPosition,
} from '@/panel/types/page';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * The links between one record's pages.
 *
 * `top` reads as a tab strip, `start` and `end` as a rail. Only the
 * direction differs; the items and their active state are the server's
 * either way.
 */
const props = defineProps<{
    items: SubNavigationItem[];
    position: SubNavigationPosition;
}>();

const { panel } = usePanel();

const prefetch = computed(() => panel.value?.prefetch ?? false);
const isTabs = computed(() => props.position === 'top');
</script>

<template>
    <nav
        :aria-label="t('shell.record_navigation')"
        :class="
            isTabs ? 'flex items-center gap-1 border-b' : 'flex flex-col gap-1'
        "
    >
        <Link
            v-for="item in items"
            :key="item.key"
            :href="item.href"
            :prefetch="prefetch"
            :aria-current="item.active ? 'page' : undefined"
            class="flex items-center gap-2 rounded-md px-3 py-2 text-sm transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :class="[
                isTabs ? '-mb-px rounded-b-none border-b-2' : '',
                item.active
                    ? isTabs
                        ? 'border-primary font-medium text-foreground'
                        : 'bg-accent font-medium text-accent-foreground'
                    : isTabs
                      ? 'border-transparent text-muted-foreground'
                      : 'text-muted-foreground',
            ]"
        >
            <component
                :is="resolveIcon(item.icon)"
                v-if="resolveIcon(item.icon)"
                class="size-4"
            />
            <span>{{ item.label }}</span>
        </Link>
    </nav>
</template>
