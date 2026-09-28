<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, LayoutGrid } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useAppearance } from '@/composables/useAppearance';
import { usePanel } from '@/panel/composables/usePanel';
import { themedPanelIcon } from '@/panel/composables/usePanelBranding';
import { resolveIcon } from '@/panel/icons/registry';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * Moves between the panels the signed-in user may enter.
 *
 * A sheet rather than a dropdown: each entry carries a brand, a name, and a
 * path, which needs more room than a menu row. It also keeps the trigger a
 * single `as-child` deep — nesting a tooltip trigger around a menu trigger
 * leaves the button with no handler, which is why the first attempt did
 * nothing when clicked.
 *
 * Renders nothing when the user may enter only one panel.
 */
const { panels, canSwitchPanels } = usePanel();
const { resolvedAppearance } = useAppearance();

const open = ref(false);

const entries = computed(() =>
    panels.value.map((entry) => ({
        ...entry,
        resolvedIcon: resolveIcon(
            themedPanelIcon(entry, resolvedAppearance.value),
        ),
    })),
);
</script>

<template>
    <Sheet v-if="canSwitchPanels" v-model:open="open">
        <SheetTrigger as-child>
            <Button
                variant="ghost"
                size="icon-sm"
                :aria-label="t('shell.switch_panel')"
            >
                <LayoutGrid />
            </Button>
        </SheetTrigger>

        <SheetContent side="right" class="gap-0">
            <SheetHeader>
                <SheetTitle>{{ t('shell.switch_panel') }}</SheetTitle>
                <SheetDescription>
                    {{
                        t('shell.switch_panel_description', {
                            panel:
                                panels.find((entry) => entry.current)?.name ??
                                t('shell.none'),
                        })
                    }}
                </SheetDescription>
            </SheetHeader>

            <nav class="flex flex-col gap-2 overflow-y-auto p-4">
                <Link
                    v-for="entry in entries"
                    :key="entry.id"
                    :href="entry.url"
                    class="flex items-center gap-3 rounded-lg border p-3 text-left transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        entry.current ? 'border-primary/40 bg-accent/50' : ''
                    "
                    :aria-current="entry.current ? 'page' : undefined"
                    @click="open = false"
                >
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
                    >
                        <component
                            :is="entry.resolvedIcon"
                            v-if="entry.resolvedIcon"
                            class="size-5"
                        />
                    </span>

                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate font-medium">
                            {{ entry.name }}
                        </span>
                        <span class="truncate text-xs text-muted-foreground">
                            {{ entry.brandName }} &middot; {{ entry.path }}
                        </span>
                    </span>

                    <Check
                        v-if="entry.current"
                        class="size-4 shrink-0 text-primary"
                    />
                </Link>
            </nav>
        </SheetContent>
    </Sheet>
</template>
