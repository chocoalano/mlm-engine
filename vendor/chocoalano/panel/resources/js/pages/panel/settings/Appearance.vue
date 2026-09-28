<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { Card, CardContent } from '@/components/ui/card';
import PageHeader from '@/panel/components/PageHeader.vue';
import type { PageMetadata } from '@/panel/types/page';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';

defineOptions({ layout: PanelLayout });

defineProps<{
    page: PageMetadata;
}>();
</script>

<template>
    <Head :title="page.title" />

    <div class="flex max-w-2xl flex-col gap-6">
        <PageHeader :heading="page.heading" :subheading="page.subheading" />

        <Card class="shadow-xs">
            <CardContent>
                <AppearanceTabs />
            </CardContent>
        </Card>
    </div>
</template>
