<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useNavigation } from '@/panel/composables/useNavigation';
import { usePanel } from '@/panel/composables/usePanel';
import { resolveIcon } from '@/panel/icons/registry';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * What a dashboard shows before anyone has put anything on it.
 *
 * The first screen after `panel:install` used to be a dashed box reading "No
 * widgets on this dashboard", which is true and useless: it names the state
 * without naming the move. A first screen is the one place a framework gets
 * to explain itself, so this one carries the two commands that fill it and
 * the destinations the panel already has.
 *
 * Everything here is read from props the shell already shares — the panel and
 * its navigation — so an empty dashboard costs no extra query.
 */
const { panel } = usePanel();
const { groups } = useNavigation();

const { copy, copied, isSupported } = useClipboard({ copiedDuring: 2000 });

const panelId = computed(() => panel.value?.id ?? 'admin');

const panelName = computed(
    () => panel.value?.name ?? t('dashboard.this_panel'),
);

/**
 * The generators, with this panel already filled in.
 *
 * A command a reader has to adapt before running is a command they have to
 * understand first, which is the wrong order on a first screen.
 */
const steps = computed(() => [
    {
        key: 'widget',
        title: t('dashboard.add_widget'),
        description: t('dashboard.add_widget_description'),
        command: `php artisan make:panel-widget OrderStats --panel=${panelId.value} --type=stats`,
    },
    {
        key: 'resource',
        title: t('dashboard.add_resource'),
        description: t('dashboard.add_resource_description'),
        command: `php artisan make:panel-resource Product --panel=${panelId.value}`,
    },
]);

/**
 * Where this panel can already go.
 *
 * The sidebar says the same thing, but a panel whose dashboard is empty is
 * usually a panel somebody has just met — and a dead-centre screen with no
 * way out reads as a broken install rather than an empty one.
 */
const destinations = computed(() =>
    groups.value
        .flatMap((group) => group.items)
        .filter((item) => !item.active)
        .slice(0, 6),
);
</script>

<template>
    <div class="flex flex-col gap-6 rounded-xl border border-dashed p-6 sm:p-8">
        <div class="flex flex-col gap-1.5">
            <h2 class="text-base font-semibold">
                {{ t('dashboard.ready', { panel: panelName }) }}
            </h2>
            <p class="max-w-prose text-sm text-muted-foreground">
                {{ t('dashboard.empty') }}
            </p>
        </div>

        <div class="grid gap-3 lg:grid-cols-2">
            <div
                v-for="step in steps"
                :key="step.key"
                class="flex flex-col gap-3 rounded-lg border bg-card p-4"
            >
                <div class="flex flex-col gap-1">
                    <p class="text-sm font-medium">{{ step.title }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ step.description }}
                    </p>
                </div>

                <div
                    class="flex items-center gap-2 rounded-md bg-muted/60 py-2 pr-1 pl-3"
                >
                    <code
                        class="min-w-0 flex-1 overflow-x-auto font-mono text-xs whitespace-pre text-foreground"
                        >{{ step.command }}</code
                    >
                    <Button
                        v-if="isSupported"
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-7 shrink-0"
                        :aria-label="t('ui.copy_command', { name: step.title })"
                        @click="copy(step.command)"
                    >
                        <component
                            :is="resolveIcon(copied ? 'check' : 'copy')"
                            class="size-3.5"
                        />
                    </Button>
                </div>
            </div>
        </div>

        <div
            v-if="destinations.length > 0"
            class="flex flex-wrap items-center gap-2 border-t pt-4"
        >
            <span class="text-sm text-muted-foreground">
                {{ t('dashboard.already_here') }}
            </span>
            <Button
                v-for="destination in destinations"
                :key="destination.href"
                as-child
                variant="outline"
                size="sm"
            >
                <Link :href="destination.href">
                    <component
                        :is="resolveIcon(destination.icon)"
                        v-if="resolveIcon(destination.icon)"
                        class="size-3.5"
                    />
                    {{ destination.label }}
                </Link>
            </Button>
        </div>
    </div>
</template>
