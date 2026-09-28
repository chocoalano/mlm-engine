<script setup lang="ts">
import type { Passkey } from '@/types/auth';

defineProps<{ passkey: Passkey }>();

defineEmits<{ remove: [id: number, onError: () => void] }>();
</script>

<template>
    <li>{{ passkey.id }}</li>
</template>
