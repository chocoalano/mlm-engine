<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import type { PanelBreadcrumbItem } from '@/panel/types/breadcrumb';

withDefaults(
    defineProps<{
        breadcrumbs?: PanelBreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <Breadcrumb v-if="breadcrumbs.length > 0">
        <BreadcrumbList>
            <template v-for="(crumb, index) in breadcrumbs" :key="index">
                <BreadcrumbItem>
                    <BreadcrumbPage v-if="crumb.current || crumb.href === null">
                        {{ crumb.label }}
                    </BreadcrumbPage>
                    <BreadcrumbLink v-else as-child>
                        <Link :href="crumb.href">{{ crumb.label }}</Link>
                    </BreadcrumbLink>
                </BreadcrumbItem>
                <BreadcrumbSeparator v-if="index !== breadcrumbs.length - 1" />
            </template>
        </BreadcrumbList>
    </Breadcrumb>
</template>
