<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import PublicFooter from '@/components/public/PublicFooter.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

/*
 * The page props reach every layout the resolver returns (§3.1.2), and
 * `AppShell` forwards its attributes to the sidebar wrapper, so
 * `inheritAttrs: false` is what keeps `records`, `record` and the rest out of
 * the DOM on `/admin`.
 */
defineOptions({ inheritAttrs: false });

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <!--
                §7.2 (2.5.8) — a crumb link is an interactive target, and
                `BreadcrumbLink`'s `<a>` is text-height on its own. `PublicLayout`
                pads its trail to the 24×24 floor with the same `[&_a]` rules;
                this is that fix for the admin and settings shells.
            -->
            <AppSidebarHeader
                :breadcrumbs="breadcrumbs"
                class="[&_a]:inline-flex [&_a]:min-h-6 [&_a]:items-center"
            />
            <slot />

            <!--
                §7.2 (3.2.6) — one mechanism for consistent help: the same
                `PublicFooter.vue`, in the same column order, on every layout —
                `/admin` and `/settings` included. §7.3 asks the sidebar shell for
                exactly one `<footer>`; because this one is rendered inside
                `<main>` it is that element, and not a second `contentinfo`
                landmark. `mt-auto` keeps it at the bottom of a short page, the
                way `PublicLayout`'s flex column does.
            -->
            <div class="mt-auto">
                <PublicFooter />
            </div>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
