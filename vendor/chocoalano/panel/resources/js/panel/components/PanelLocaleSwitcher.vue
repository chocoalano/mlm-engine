<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, Languages } from '@lucide/vue';
import { ref } from 'vue';
import { useTranslator } from '@/composables/useTranslator';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePanel } from '@/panel/composables/usePanel';

/**
 * Changes the language the panel is read in.
 *
 * Renders nothing unless the panel offers more than one — `locales` is null
 * otherwise, which is the same arrangement the tenant switcher uses. A panel
 * with one language has nothing to switch to, and a menu offering the
 * language already on screen is worse than no menu.
 *
 * A POST rather than a link, because it writes: the choice goes into the
 * session, and a language sitting behind a GET is a language somebody's
 * prefetcher can change.
 *
 * `preserveScroll` and no `preserveState`: the response is a fresh render of
 * the same page in the new language, so every label, empty state and
 * confirmation has to be rebuilt — but the reader was part-way down a table
 * and should stay there.
 *
 * Each language is written in its own words. Somebody looking for their
 * language is looking for the word they would use for it, and a reader who
 * cannot read the current locale cannot read "Indonesian" in it either.
 */
const { t } = useTranslator();
const { locales } = usePanel();

const open = ref(false);

function choose(code: string): void {
    const target = locales.value;

    if (target === null || code === target.current) {
        return;
    }

    open.value = false;

    router.post(target.url, { locale: code }, { preserveScroll: true });
}
</script>

<template>
    <DropdownMenu v-if="locales !== null" v-model:open="open">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon-sm"
                :aria-label="t('shell.switch_language')"
            >
                <Languages />
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-48">
            <DropdownMenuLabel>
                {{ t('shell.switch_language') }}
            </DropdownMenuLabel>

            <DropdownMenuSeparator />

            <DropdownMenuItem
                v-for="entry in locales.available"
                :key="entry.code"
                :disabled="entry.current"
                class="flex items-center justify-between gap-2"
                :aria-current="entry.current ? 'true' : undefined"
                @select="choose(entry.code)"
            >
                <span class="truncate">{{ entry.name }}</span>
                <Check v-if="entry.current" class="size-4 shrink-0" />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
