<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { dashboard } from '@/routes/admin';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard · Admin" />

    <!--
        §7.2 (2.1.1) — the dashboard body is a scrollable region whose
        content is `aria-hidden` placeholder art, so it has no focusable
        children of its own. `tabindex="0"` puts it in the tab order so it
        can be scrolled with the keyboard, and the region takes its name
        from the page heading — the same contract as the `/privacy` tables.
    -->
    <div
        class="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4"
        tabindex="0"
        role="region"
        aria-labelledby="dashboard-heading"
    >
        <Heading id="dashboard-heading" as="h1" title="Dashboard" />

        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div
                class="relative aspect-video overflow-hidden rounded-md border-2 border-ink"
            >
                <PlaceholderPattern />
            </div>
            <div
                class="relative aspect-video overflow-hidden rounded-md border-2 border-ink"
            >
                <PlaceholderPattern />
            </div>
            <div
                class="relative aspect-video overflow-hidden rounded-md border-2 border-ink"
            >
                <PlaceholderPattern />
            </div>
        </div>
        <div
            class="relative min-h-[100vh] flex-1 rounded-md border-2 border-ink md:min-h-min"
        >
            <PlaceholderPattern />
        </div>
    </div>
</template>
