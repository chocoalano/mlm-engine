<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import PanelAuthLayout from '@/panel/layouts/PanelAuthLayout.vue';
import type { PanelDefinition } from '@/panel/types/panel';
import PanelBlankLayout from '@/panel/layouts/PanelBlankLayout.vue';
import { onScopeDispose } from 'vue';
import { useCountdown } from '@/composables/useCountdown';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

defineOptions({ layout: PanelBlankLayout });

/**
 * The emailed-code challenge.
 *
 * Reached only by somebody already signed in whose session has not answered
 * one — the way password confirmation works, and for the same reason: a new
 * session on a new device is challenged even though the password was right.
 */
const props = defineProps<{
    panel: PanelDefinition;
    /** Obscured: enough to recognise the inbox, not enough to learn it. */
    sentTo: string;
    retryAfter: number;
}>();

/**
 * The wait before another code may be asked for.
 *
 * `retryAfter` is a number of seconds measured when the page was rendered, and
 * it was used directly: the button said "wait 30 seconds" and went on saying
 * it, disabled, for as long as the page was open. Nothing decremented it. A
 * user who waited the thirty seconds saw exactly what they saw at the start,
 * and the only way out was to reload a page that does not look reloadable.
 *
 * The server stays the authority — this counts down the value it sent and
 * never invents one. Asking for another code posts and comes back through
 * `challenge()`, which sends a fresh `retryAfter`; the watch inside the
 * composable restarts the deadline from it, so a refusal that returns a new
 * wait is honoured and a failure that returns the same one does not silently
 * extend it.
 */
const { remaining, active, restart } = useCountdown(() => props.retryAfter);

/**
 * Asking for another code posts and comes back through the challenge page,
 * which sends a fresh `retryAfter`. Usually that is a different number and the
 * countdown restarts on its own — but a rate limit that is always sixty
 * seconds sends sixty again, and "the same value" is not a change Vue can see.
 * So the arrival of the answer is the signal, rather than the value in it.
 */
onScopeDispose(router.on('success', () => restart()));
</script>

<template>
    <PanelAuthLayout
        :panel="panel"
        :title="t('auth.check_email')"
        :description="t('auth.email_code_description', { email: sentTo })"
    >
        <Head :title="`${t('auth.sign_in_code')} · ${panel.brandName}`" />

        <Form
            v-slot="{ errors, processing }"
            :action="`/${panel.path}/two-factor/verify`"
            method="post"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-2">
                <Label for="code">{{ t('auth.code') }}</Label>
                <Input
                    id="code"
                    aria-describedby="code-error"
                    :aria-invalid="errors.code ? true : undefined"
                    name="code"
                    required
                    autofocus
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    placeholder="000000"
                    class="text-center text-lg tracking-[0.5em] tabular-nums"
                />
                <InputError id="code-error" :message="errors.code" />
            </div>

            <Button type="submit" class="w-full" :disabled="processing">
                <Spinner v-if="processing" />
                {{ t('auth.continue') }}
            </Button>
        </Form>

        <Form
            v-slot="{ processing }"
            :action="`/${panel.path}/two-factor/send`"
            method="post"
        >
            <Button
                type="submit"
                variant="ghost"
                class="w-full"
                :disabled="processing || active"
            >
                {{
                    active
                        ? t('auth.wait_before_retry', { seconds: remaining })
                        : t('auth.send_another_code')
                }}
            </Button>
        </Form>
    </PanelAuthLayout>
</template>
