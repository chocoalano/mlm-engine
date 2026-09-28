<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Toaster } from '@/components/ui/sonner';
import PanelLocaleSwitcher from '@/panel/components/PanelLocaleSwitcher.vue';
import { usePanelBranding } from '@/panel/composables/usePanelBranding';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';
import { resolveIcon } from '@/panel/icons/registry';
import type { PanelDefinition } from '@/panel/types/panel';

/**
 * The frame a panel's own auth pages sit in.
 *
 * Separate from the panel shell because none of the shell applies to a guest:
 * there is no navigation to draw, no notifications to count, and no user
 * menu. What is left is the panel's identity, which is the whole reason a
 * panel has a front door of its own rather than sharing the application's.
 *
 * One exception: the language switcher. A login screen is exactly where
 * somebody notices they are reading the wrong language, and it has to work
 * before there is an account to remember the choice on — which is why the
 * locale route has a guest twin outside the auth stack.
 */
const props = defineProps<{
    panel: PanelDefinition;
    title: string;
    description?: string;
}>();

const { iconName, logo } = usePanelBranding(() => props.panel);
const icon = computed(() => resolveIcon(iconName.value));

/**
 * The panel's palette applies here too.
 *
 * This layout exists because "a panel has a front door of its own rather than
 * sharing the application's" — and the brand mark below is drawn with
 * `bg-primary`. Without this the door was the package's default indigo
 * whatever the panel had configured, so the one screen whose entire purpose
 * is the panel's identity was the one screen that did not carry it.
 *
 * Called for its effect: the composable writes the palette onto the document
 * element, which is what the toaster teleported out of this layout needs.
 */
usePanelStyling();
</script>

<template>
    <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6">
        <div class="absolute top-4 right-4">
            <PanelLocaleSwitcher />
        </div>

        <div class="flex w-full max-w-sm flex-col gap-6">
            <Link
                :href="`/${panel.path}`"
                class="flex items-center gap-2 self-center font-medium"
            >
                <span
                    class="flex size-9 items-center justify-center rounded-md bg-primary text-primary-foreground"
                >
                    <img
                        v-if="logo"
                        :src="logo"
                        alt=""
                        class="size-5 object-contain"
                    />
                    <component :is="icon" v-else-if="icon" class="size-5" />
                </span>
                <span>{{ panel.brandName }}</span>
            </Link>

            <div class="flex flex-col gap-2 text-center">
                <h1 class="text-xl font-medium">{{ title }}</h1>
                <p v-if="description" class="text-sm text-muted-foreground">
                    {{ description }}
                </p>
            </div>

            <slot />
        </div>

        <Toaster />
    </div>
</template>
