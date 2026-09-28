<script setup lang="ts">
import { Eye, EyeOff } from '@lucide/vue';
import { ref, useTemplateRef } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();

const showPassword = ref(false);
const inputRef = useTemplateRef('inputRef');

/*
 * The toggle is a control, so it is in the tab order.
 *
 * It carried `tabindex="-1"`, which is the one attribute that takes a working
 * feature away from exactly the people most likely to want it: somebody typing
 * a long generated password on a keyboard could not reach the button that
 * lets them check it. The usual argument for `-1` here is that the button sits
 * between the password field and the submit — but that is an argument about
 * one extra Tab press, against a feature being unreachable.
 *
 * `aria-pressed` rather than only a changing label: the label says what
 * pressing it will do next, and this says what state it is in now. Together
 * they answer both "what does this do" and "is it on".
 */

defineExpose({
    $el: inputRef,
    focus: () => inputRef.value?.$el?.focus(),
});
</script>

<template>
    <div class="relative">
        <Input
            ref="inputRef"
            :type="showPassword ? 'text' : 'password'"
            :class="cn('pr-10', props.class)"
            v-bind="$attrs"
        />
        <button
            type="button"
            :class="
                cn(
                    'absolute inset-y-0 right-0 flex items-center rounded-r-md px-3 text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none',
                )
            "
            :aria-label="
                showPassword ? t('ui.hide_password') : t('ui.show_password')
            "
            :aria-pressed="showPassword"
            @click="showPassword = !showPassword"
        >
            <EyeOff v-if="showPassword" class="size-4" />
            <Eye v-else class="size-4" />
        </button>
    </div>
</template>
