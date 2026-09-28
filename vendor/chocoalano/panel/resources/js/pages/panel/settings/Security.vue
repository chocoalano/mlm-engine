<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/InputError.vue';
import type { Props as ManagePasskeysProps } from '@/components/ManagePasskeys.vue';
import ManagePasskeys from '@/components/ManagePasskeys.vue';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import PageHeader from '@/panel/components/PageHeader.vue';
import type { PageMetadata } from '@/panel/types/page';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

defineOptions({ layout: PanelLayout });

type Props = {
    page: PageMetadata;
    passwordRules: string;
    /** The panel's own second factor: a one-time code sent to this address. */
    emailCodeEnabled: boolean;
    emailCodeUrls: { enable: string; disable: string };
} & ManagePasskeysProps &
    ManageTwoFactorProps;

const props = defineProps<Props>();
</script>

<template>
    <Head :title="page.title" />

    <div class="flex max-w-2xl flex-col gap-6">
        <PageHeader :heading="page.heading" :subheading="page.subheading" />

        <Card class="shadow-xs">
            <CardContent>
                <Form
                    v-slot="{ errors, processing }"
                    v-bind="SecurityController.update.form()"
                    :options="{ preserveScroll: true }"
                    reset-on-success
                    :reset-on-error="[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]"
                    class="space-y-6"
                >
                    <div class="grid gap-2">
                        <Label for="current_password">
                            {{ t('settings.current_password') }}
                        </Label>
                        <PasswordInput
                            id="current_password"
                            aria-describedby="current_password-error"
                            :aria-invalid="
                                errors.current_password ? true : undefined
                            "
                            name="current_password"
                            class="block w-full"
                            autocomplete="current-password"
                            :placeholder="t('settings.current_password')"
                        />
                        <InputError
                            id="current_password-error"
                            :message="errors.current_password"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">{{
                            t('settings.new_password')
                        }}</Label>
                        <PasswordInput
                            id="password"
                            aria-describedby="password-error"
                            :aria-invalid="errors.password ? true : undefined"
                            name="password"
                            class="block w-full"
                            autocomplete="new-password"
                            :placeholder="t('settings.new_password')"
                            :passwordrules="props.passwordRules"
                        />
                        <InputError
                            id="password-error"
                            :message="errors.password"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password_confirmation">
                            {{ t('settings.confirm_password') }}
                        </Label>
                        <PasswordInput
                            id="password_confirmation"
                            aria-describedby="password_confirmation-error"
                            :aria-invalid="
                                errors.password_confirmation ? true : undefined
                            "
                            name="password_confirmation"
                            class="block w-full"
                            autocomplete="new-password"
                            :placeholder="t('settings.confirm_password')"
                            :passwordrules="props.passwordRules"
                        />
                        <InputError
                            id="password_confirmation-error"
                            :message="errors.password_confirmation"
                        />
                    </div>

                    <Button
                        :disabled="processing"
                        data-test="update-password-button"
                    >
                        {{ t('settings.save') }}
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <ManageTwoFactor
            :can-manage-two-factor="canManageTwoFactor"
            :requires-confirmation="requiresConfirmation"
            :two-factor-enabled="twoFactorEnabled"
        />

        <ManagePasskeys
            :can-manage-passkeys="canManagePasskeys"
            :passkeys="passkeys"
        />

        <!--
            The panel's own channel, for somebody who will not install an
            authenticator app. Weaker than TOTP and much stronger than
            nothing, which is the choice it actually competes with.
        -->
        <Card class="shadow-xs">
            <CardContent class="flex flex-col gap-4">
                <div>
                    <h2 class="text-base font-medium">
                        {{ t('settings.email_codes') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            emailCodeEnabled
                                ? t('settings.email_codes_on')
                                : t('settings.email_codes_off')
                        }}
                    </p>
                </div>

                <Form
                    v-slot="{ processing }"
                    :action="
                        emailCodeEnabled
                            ? emailCodeUrls.disable
                            : emailCodeUrls.enable
                    "
                    method="post"
                >
                    <Button
                        type="submit"
                        :variant="emailCodeEnabled ? 'outline' : 'default'"
                        :disabled="processing"
                    >
                        {{
                            emailCodeEnabled
                                ? t('settings.turn_off')
                                : t('settings.turn_on')
                        }}
                    </Button>
                </Form>
            </CardContent>
        </Card>
    </div>
</template>
