<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
import PublicFooter from '@/components/public/PublicFooter.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

/*
 * Nothing in the resolver returns this variant today — `AppLayout.vue` renders
 * `AppSidebarLayout` — but §7.3 gives `AppHeaderLayout` the same landmark
 * contract, so the §7.2 (3.2.6) footer is rendered here too rather than left as
 * a trap for whoever switches the shell.
 *
 * `inheritAttrs: false` for the same reason as `AppSidebarLayout`: the page
 * props arrive on every layout the resolver returns (§3.1.2).
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
    <AppShell variant="header">
        <AppHeader :breadcrumbs="breadcrumbs" />
        <AppContent variant="header">
            <slot />

            <!--
                §7.2 (3.2.6) — the one consistent-help mechanism, in the same
                column order as every other layout. It is inside `<main>`, so it
                is the shell's single `<footer>` and not a `contentinfo`
                landmark.
            -->
            <div class="mt-auto">
                <PublicFooter />
            </div>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
