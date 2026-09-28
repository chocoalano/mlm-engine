<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePanel } from '@/panel/composables/usePanel';
import { resolveIcon } from '@/panel/icons/registry';
import type { NavigationItem } from '@/panel/types/navigation';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * One entry of the header's navigation row.
 *
 * The header used to render `item.href` and stop there. Navigation metadata
 * carries `children`, the sidebar draws them, and the header dropped them —
 * so a panel laid out with the header shell had destinations its own
 * navigation declared and no way to reach: not hidden by permission, simply
 * never drawn. Anyone in that panel had to know the URL.
 *
 * An item with children becomes a menu; one without stays a link, because a
 * menu that opens onto a single destination is a press for nothing. The menu
 * lists the parent's own page first — a parent has an `href` as well as
 * children, and choosing between them would lose one of the two.
 *
 * Reka's `DropdownMenu` owns the keyboard: arrows, Home/End, Escape, typeahead
 * and returning focus to the trigger. Nothing here layers key handling on top
 * of it.
 */
const props = defineProps<{ item: NavigationItem }>();

const { panel } = usePanel();

const prefetch = computed(() => panel.value?.prefetch ?? false);

const hasChildren = computed(() => props.item.children.length > 0);

const icon = computed(() =>
    resolveIcon(props.item.active ? props.item.activeIcon : props.item.icon),
);

/**
 * Whether the page being looked at is inside this item.
 *
 * The parent link itself may not be active while one of its children is, and
 * a trigger that says nothing in that case leaves the current section
 * unidentifiable from the header alone.
 */
const activeDescendant = computed(() =>
    props.item.children.some((child) => child.active),
);

const withinSection = computed(
    () => props.item.active || activeDescendant.value,
);

const TRIGGER_CLASSES =
    'flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm whitespace-nowrap transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none';
</script>

<template>
    <!-- No children: exactly what the header rendered before. -->
    <Link
        v-if="!hasChildren && !item.fullPage"
        :href="item.href"
        :prefetch="prefetch"
        :aria-current="item.active ? 'page' : undefined"
        :class="[
            TRIGGER_CLASSES,
            item.active
                ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                : '',
        ]"
    >
        <component :is="icon" v-if="icon" class="size-4" />
        {{ item.label }}
    </Link>

    <!--
        A full-page destination leaves the SPA, so it is a plain anchor and is
        never prefetched — the same rule the sidebar follows.
    -->
    <a
        v-else-if="!hasChildren"
        :href="item.href"
        :aria-current="item.active ? 'page' : undefined"
        :class="[
            TRIGGER_CLASSES,
            item.active
                ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                : '',
        ]"
    >
        <component :is="icon" v-if="icon" class="size-4" />
        {{ item.label }}
    </a>

    <DropdownMenu v-else>
        <DropdownMenuTrigger
            :class="[
                TRIGGER_CLASSES,
                withinSection
                    ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                    : '',
            ]"
        >
            <component :is="icon" v-if="icon" class="size-4" />
            {{ item.label }}
            <!--
                Said in words, not only in the background colour: which section
                you are in is information, and a highlight is not readable by
                anything that is not looking at it.
            -->
            <span v-if="withinSection" class="sr-only">
                {{ t('shell.current_section') }}
            </span>
            <ChevronDown class="size-3.5 opacity-60" />
        </DropdownMenuTrigger>

        <DropdownMenuContent align="start" class="min-w-48">
            <DropdownMenuItem as-child>
                <a
                    v-if="item.fullPage"
                    :href="item.href"
                    :aria-current="item.active ? 'page' : undefined"
                >
                    {{ item.label }}
                </a>
                <Link
                    v-else
                    :href="item.href"
                    :prefetch="prefetch"
                    :aria-current="item.active ? 'page' : undefined"
                >
                    {{ item.label }}
                </Link>
            </DropdownMenuItem>

            <DropdownMenuSeparator />

            <DropdownMenuItem
                v-for="child in item.children"
                :key="child.href"
                as-child
            >
                <a
                    v-if="child.fullPage"
                    :href="child.href"
                    :aria-current="child.active ? 'page' : undefined"
                >
                    {{ child.label }}
                </a>
                <Link
                    v-else
                    :href="child.href"
                    :prefetch="prefetch"
                    :aria-current="child.active ? 'page' : undefined"
                >
                    {{ child.label }}
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
