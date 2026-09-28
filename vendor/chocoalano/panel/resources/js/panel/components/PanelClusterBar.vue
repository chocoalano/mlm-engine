<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { resolveIcon } from '@/panel/icons/registry';
import type { ClusterNavigation } from '@/panel/types/page';

/**
 * The sub-navigation of the cluster a page belongs to.
 *
 * Rendered either as a bar under the header or as a column beside the
 * content — the same links either way, because where a set of pages is
 * listed is a layout decision the panel made once and not something each
 * page has an opinion about.
 *
 * The column is a column only where there is room for one. It was `w-56
 * shrink-0` unconditionally, which on a 320px screen leaves the page it is
 * meant to navigate about ninety pixels of width — the navigation wins and
 * the content it points at loses. Below `lg` it lays out as a row of wrapping
 * links above the content instead, which is the same set of destinations in
 * the space that is actually available.
 *
 * Wrapping rather than a dropdown or a sheet: a cluster is a handful of
 * sibling pages, usually two to five, and hiding five links behind a trigger
 * costs a press and a mental step to save a line of wrapped text.
 *
 * Never rendered empty: the server sends null when the cluster has nothing
 * this user may see.
 */
defineProps<{
    cluster: ClusterNavigation;
    /** `header` lays the links out in a row; `right-bar` stacks them. */
    orientation: 'row' | 'column';
}>();
</script>

<template>
    <nav
        :aria-label="cluster.label"
        :class="
            orientation === 'row'
                ? 'flex flex-wrap items-center gap-1 border-b pb-3'
                : 'flex flex-wrap items-center gap-1 border-b pb-3 order-first lg:order-none lg:w-56 lg:shrink-0 lg:flex-col lg:items-stretch lg:border-b-0 lg:pb-0'
        "
    >
        <!--
            The heading belongs above a column and would be a stray word in
            front of a row, so it is shown only where the column is.
        -->
        <p
            v-if="orientation === 'column'"
            class="hidden px-2 pb-1 text-xs font-medium text-muted-foreground lg:block"
        >
            {{ cluster.label }}
        </p>

        <Link
            v-for="item in cluster.items"
            :key="item.href"
            :href="item.href"
            class="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :class="
                item.active
                    ? 'bg-accent font-medium text-accent-foreground'
                    : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground'
            "
            :aria-current="item.active ? 'page' : undefined"
        >
            <!-- The active icon is sent with every item, so the swap happens
                 on a client-side navigation without a round trip. -->
            <component
                :is="resolveIcon(item.active ? item.activeIcon : item.icon)"
                v-if="resolveIcon(item.active ? item.activeIcon : item.icon)"
                class="size-4"
            />
            {{ item.label }}
            <span
                v-if="item.badge !== null"
                class="rounded-full bg-muted px-1.5 text-xs text-muted-foreground lg:ml-auto"
            >
                {{ item.badge }}
            </span>
        </Link>
    </nav>
</template>
