<script setup lang="ts">
import type { User } from '@/types/auth';

defineProps<{ user: User; showEmail?: boolean }>();
</script>

<template>
    <span>{{ user.name }}</span>
</template>
