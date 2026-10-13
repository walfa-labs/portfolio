<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const { href, class: className } = defineProps<{
    href: NonNullable<InertiaLinkProps['href']>;
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <Link
        :href="href"
        data-slot="link-card"
        :class="
            cn(
                'block rounded-md border-2 border-ink bg-card p-6 text-ink shadow-hard-sm transition hover:-translate-x-0.5 hover:-translate-y-0.5 hover:shadow-hard focus-visible:-translate-x-0.5 focus-visible:-translate-y-0.5 focus-visible:shadow-hard active:translate-x-0 active:translate-y-0 active:shadow-none motion-reduce:transition-none',
                className,
            )
        "
    >
        <slot />
    </Link>
</template>
