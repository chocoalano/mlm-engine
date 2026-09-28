<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { Loader2Icon } from "@lucide/vue"
import { cn } from "@/lib/utils"
import { useTranslator } from "@/composables/useTranslator"

const { t } = useTranslator()

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <Loader2Icon
    data-slot="spinner"
    role="status"
    :aria-label="t('ui.loading')"
    :class="cn('size-4 animate-spin', props.class)"
  />
</template>
