<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import LinkCard from '@/components/LinkCard.vue';

const { label, title, href } = defineProps<{
    label: string;
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
}>();
</script>

<template>
    <!--
        §6.5 / §7.5(3) — this is the page's single rotated element. The rotation
        is a `rotate` declaration and `LinkCard`'s lift is a `translate` one, so
        the two compose instead of overwriting each other, and the outline stays
        attached to the transformed box.
    -->
    <LinkCard
        :href="href"
        class="rotate-[-1.5deg] bg-tone-marigold shadow-hard-lg hover:shadow-hard-lg focus-visible:shadow-hard-lg"
    >
        <p class="font-mono text-label font-medium tracking-widest uppercase">
            {{ label }}
        </p>

        <h2
            class="mt-2 font-display text-xl font-bold tracking-tight text-balance"
        >
            {{ title }}
        </h2>

        <span
            class="mt-4 inline-flex items-center gap-2 text-sm font-semibold underline decoration-2 underline-offset-4"
        >
            View project
            <ArrowRight class="size-4" aria-hidden="true" />
        </span>
    </LinkCard>
</template>
