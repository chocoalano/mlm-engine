<script setup lang="ts">
import { MoreHorizontal } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { resolveIcon } from '@/panel/icons/registry';
import type { ActionDefinition } from '@/panel/types/action';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * Row actions collapse into one menu so a wide table does not grow a column
 * of buttons per action.
 */
defineProps<{
    actions: ActionDefinition[];
}>();

const emit = defineEmits<{ run: [action: ActionDefinition] }>();
</script>

<template>
    <DropdownMenu v-if="actions.length > 0">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon-sm"
                :aria-label="t('actions.row_actions')"
            >
                <MoreHorizontal />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-44">
            <DropdownMenuItem
                v-for="action in actions"
                :key="action.name"
                :variant="
                    action.variant === 'destructive' ? 'destructive' : 'default'
                "
                @select="emit('run', action)"
            >
                <component
                    :is="resolveIcon(action.icon)"
                    v-if="resolveIcon(action.icon)"
                />
                {{ action.label }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
