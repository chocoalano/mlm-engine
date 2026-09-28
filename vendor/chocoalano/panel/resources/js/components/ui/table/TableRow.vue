<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <!--
    `bg-background` by default, and it is what makes a frozen column work: a
    pinned cell is `bg-inherit` so that it still takes the row's hover and
    selected colour, and `inherit` can only inherit what the row has. A row
    with no background of its own hands the pinned cell transparency, and the
    columns scrolling under it show straight through.

    Overridable, and overridden: a `bg-*` class passed in wins through
    `cn()`'s conflict resolution, which is how a group band or a summary row
    still carries its own tint.
  -->
  <tr
    data-slot="table-row"
    :class="cn('bg-background hover:bg-muted/50 data-[state=selected]:bg-muted border-b transition-colors', props.class)"
  >
    <slot />
  </tr>
</template>
