<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import PanelAuthLayout from '@/panel/layouts/PanelAuthLayout.vue';
import type { PanelDefinition } from '@/panel/types/panel';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
import PanelBlankLayout from '@/panel/layouts/PanelBlankLayout.vue';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

defineOptions({ layout: PanelBlankLayout });

defineProps<{
    panel: PanelDefinition;
    status?: string;
}>();
</script>

<template>
    <PanelAuthLayout
        :panel="panel"
        :title="t('auth.verify_email')"
        :description="t('auth.verify_email_description')"
    >
        <Head :title="`${t('auth.verify_email_title')} · ${panel.brandName}`" />

        <div
            v-if="status === 'verification-link-sent'"
            class="mb-4 text-center text-sm font-medium text-success"
        >
            {{ t('auth.verify_email_sent') }}
        </div>

        <Form
            v-slot="{ processing }"
            v-bind="send.form()"
            class="flex flex-col gap-4"
        >
            <Button type="submit" :disabled="processing">
                <Spinner v-if="processing" />
                {{ t('auth.resend_link') }}
            </Button>

            <Link
                :href="logout()"
                as="button"
                class="text-center text-sm text-muted-foreground underline underline-offset-4"
            >
                {{ t('auth.log_out') }}
            </Link>
        </Form>
    </PanelAuthLayout>
</template>
