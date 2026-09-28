<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import PanelAuthLayout from '@/panel/layouts/PanelAuthLayout.vue';
import type { PanelDefinition } from '@/panel/types/panel';
import { email } from '@/routes/password';
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
        :title="t('auth.forgot_password')"
        :description="t('auth.forgot_password_description')"
    >
        <Head :title="`${t('auth.forgot_password')} · ${panel.brandName}`" />

        <div
            v-if="status"
            class="mb-4 text-center text-sm font-medium text-success"
        >
            {{ status }}
        </div>

        <Form
            v-slot="{ errors, processing }"
            v-bind="email.form()"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-2">
                <Label for="email">{{ t('auth.email') }}</Label>
                <Input
                    id="email"
                    aria-describedby="email-error"
                    :aria-invalid="errors.email ? true : undefined"
                    type="email"
                    name="email"
                    required
                    autofocus
                />
                <InputError id="email-error" :message="errors.email" />
            </div>

            <Button type="submit" class="w-full" :disabled="processing">
                <Spinner v-if="processing" />
                {{ t('auth.email_reset_link') }}
            </Button>

            <p class="text-center text-sm text-muted-foreground">
                <TextLink :href="`/${panel.path}/login`">
                    {{ t('auth.back_to_login') }}
                </TextLink>
            </p>
        </Form>
    </PanelAuthLayout>
</template>
