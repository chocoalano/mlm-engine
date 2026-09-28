<script setup lang="ts">
/**
 * A validation message.
 *
 * The `id` is what makes it reachable: a control names it in
 * `aria-describedby`, so the message is announced when the control is focused
 * rather than only being visible beside it. Optional, because this is also
 * used on the starter kit's own forms where there is nothing to point at it.
 *
 * `role="alert"` rather than a plain paragraph: the message appears after a
 * submit that has already moved focus somewhere, and an error nobody is told
 * about is an error nobody knows arrived.
 */
defineProps<{
    message?: string;
    id?: string;
}>();
</script>

<template>
    <div v-show="message">
        <p :id="id" role="alert" class="text-sm text-destructive">
            {{ message }}
        </p>
    </div>
</template>
