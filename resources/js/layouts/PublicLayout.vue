<script setup lang="ts">
import { setLucideProps } from '@lucide/vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import PublicFooter from '@/components/public/PublicFooter.vue';
import PublicHeader from '@/components/public/PublicHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

/*
 * Pages hand the trail to this layout rather than rendering it themselves: the
 * index pages do it statically through `defineOptions({ layout: { breadcrumbs } })`
 * and `/writing/{slug}` dynamically through `setLayoutProps()`, both of which
 * land on the layout the resolver returns (§3.1.2).
 *
 * The rest of the page props arrive on the layout component too, so
 * `inheritAttrs: false` keeps them out of the DOM.
 */
defineOptions({ inheritAttrs: false });

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

/* §6.6 — `AppShell.vue` sets this once for the admin and settings shells, but the
   public shell never renders `AppShell`, so it sets the same 2.5 stroke here. */
setLucideProps({ strokeWidth: 2.5 });
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background">
        <PublicHeader />

        <main
            id="main-content"
            tabindex="-1"
            class="mx-auto w-full max-w-6xl flex-1 px-4 py-10 sm:px-6 lg:py-14"
        >
            <!--
                §3.4 — every page except `/` carries a trail. The `[&_a]` rules
                pad each crumb link to the 24×24 CSS px floor of §7.2 (2.5.8).
            -->
            <div
                v-if="breadcrumbs.length"
                class="mb-8 [&_a]:inline-flex [&_a]:min-h-6 [&_a]:items-center"
            >
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </div>

            <slot />
        </main>

        <PublicFooter />

        <Toaster />
    </div>
</template>
