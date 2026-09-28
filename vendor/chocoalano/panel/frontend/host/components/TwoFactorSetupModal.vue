<script setup lang="ts">
defineProps<{
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
}>();

defineModel<boolean>('isOpen', { default: false });
</script>

<template>
    <div />
</template>
