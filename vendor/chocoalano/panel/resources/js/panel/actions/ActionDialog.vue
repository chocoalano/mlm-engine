<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { ActionDefinition } from '@/panel/types/action';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * Confirmation for a destructive or irreversible action.
 *
 * The copy comes from the server-side action definition, so the warning and
 * the thing being warned about cannot drift apart.
 */
defineProps<{
    action: ActionDefinition | null;
    processing: boolean;
}>();

const emit = defineEmits<{ confirm: []; cancel: [] }>();
</script>

<template>
    <Dialog
        :open="action !== null"
        @update:open="(open) => !open && emit('cancel')"
    >
        <DialogContent v-if="action?.confirmation" class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ action.confirmation.heading }}</DialogTitle>
                <DialogDescription>
                    {{ action.confirmation.description }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    variant="ghost"
                    :disabled="processing"
                    @click="emit('cancel')"
                >
                    {{ t('actions.cancel') }}
                </Button>
                <Button
                    :variant="action.variant"
                    :disabled="processing"
                    @click="emit('confirm')"
                >
                    <Spinner v-if="processing" class="size-4" />
                    {{ action.confirmation.button }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
