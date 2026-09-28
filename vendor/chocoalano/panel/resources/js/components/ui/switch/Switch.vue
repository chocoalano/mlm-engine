<script setup lang="ts">
import type { SwitchRootEmits, SwitchRootProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import {
  SwitchRoot,
  SwitchThumb,
  useForwardPropsEmits,
} from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<SwitchRootProps & { class?: HTMLAttributes["class"] }>()

const emits = defineEmits<SwitchRootEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <!--
    The button is the target; the track is only what it looks like.

    It used to be one element: the button *was* the 32×18 track, so the thing
    a finger had to hit was 32×18. Padding could not fix that — the track is
    the button's own background, so padding would have made the track bigger
    rather than the target.

    So the button is now a transparent box that holds a track. On a phone it
    is 44×44; with a mouse it stays compact. The visible switch is unchanged
    at either size, which is the point: a target is not a glyph.
  -->
  <SwitchRoot
    v-slot="slotProps"
    data-slot="switch"
    v-bind="forwarded"
    :class="cn(
      'peer group inline-flex size-8 shrink-0 items-center justify-center rounded-md outline-none disabled:cursor-not-allowed disabled:opacity-50 max-sm:size-11',
      props.class,
    )"
  >
    <span
      data-slot="switch-track"
      :class="cn(
        'group-data-[state=checked]:bg-primary group-data-[state=unchecked]:bg-input group-focus-visible:border-ring group-focus-visible:ring-ring/50 dark:group-data-[state=unchecked]:bg-input/80 pointer-events-none inline-flex h-[1.15rem] w-8 shrink-0 items-center rounded-full border border-transparent shadow-xs transition-all group-focus-visible:ring-3',
      )"
    >
      <SwitchThumb
        data-slot="switch-thumb"
        :class="cn('bg-background dark:group-data-[state=unchecked]:bg-foreground dark:group-data-[state=checked]:bg-primary-foreground pointer-events-none block size-4 rounded-full ring-0 transition-transform group-data-[state=checked]:translate-x-[calc(100%-2px)] group-data-[state=unchecked]:translate-x-0')"
      >
        <slot name="thumb" v-bind="slotProps" />
      </SwitchThumb>
    </span>
  </SwitchRoot>
</template>
