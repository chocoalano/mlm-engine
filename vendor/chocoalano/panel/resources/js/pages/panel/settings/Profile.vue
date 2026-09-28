<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PageHeader from '@/panel/components/PageHeader.vue';
import type { PageMetadata } from '@/panel/types/page';
import { send } from '@/routes/verification';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

defineOptions({ layout: PanelLayout });

/**
 * The panel's profile page.
 *
 * It posts to the application's own ProfileController, which is the single
 * place a profile is written; only the shell around the form is the panel's.
 */
withDefaults(
    defineProps<{
        page: PageMetadata;
        mustVerifyEmail?: boolean;
        status?: string | null;
    }>(),
    {
        mustVerifyEmail: false,
        status: null,
    },
);

const inertiaPage = usePage();
const user = computed(() => inertiaPage.props.auth.user);
</script>

<template>
    <Head :title="page.title" />

    <div class="flex max-w-2xl flex-col gap-6">
        <PageHeader :heading="page.heading" :subheading="page.subheading" />

        <Card class="shadow-xs">
            <CardContent>
                <Form
                    v-slot="{ errors, processing }"
                    v-bind="ProfileController.update.form()"
                    class="space-y-6"
                >
                    <div class="grid gap-2">
                        <Label for="name">{{ t('settings.name') }}</Label>
                        <Input
                            id="name"
                            aria-describedby="name-error"
                            :aria-invalid="errors.name ? true : undefined"
                            class="block w-full"
                            name="name"
                            :default-value="user.name"
                            required
                            autocomplete="name"
                            :placeholder="t('settings.full_name')"
                        />
                        <InputError id="name-error" :message="errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">{{ t('settings.email') }}</Label>
                        <Input
                            id="email"
                            aria-describedby="email-error"
                            :aria-invalid="errors.email ? true : undefined"
                            type="email"
                            class="block w-full"
                            name="email"
                            :default-value="user.email"
                            required
                            autocomplete="username"
                            :placeholder="t('settings.email')"
                        />
                        <InputError id="email-error" :message="errors.email" />
                    </div>

                    <div v-if="mustVerifyEmail && !user.email_verified_at">
                        <p class="text-sm text-muted-foreground">
                            {{ t('settings.email_unverified') }}
                            <Link
                                :href="send()"
                                as="button"
                                class="text-foreground underline underline-offset-4"
                            >
                                {{ t('settings.email_resend') }}
                            </Link>
                        </p>

                        <div
                            v-if="status === 'verification-link-sent'"
                            class="mt-2 text-sm font-medium text-success"
                        >
                            {{ t('settings.email_resent') }}
                        </div>
                    </div>

                    <Button
                        :disabled="processing"
                        data-test="update-profile-button"
                    >
                        {{ t('settings.save') }}
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <DeleteUser />
    </div>
</template>
