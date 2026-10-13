<script setup lang="ts">
import { computed } from 'vue';

type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small' | 'display';
    as?: 'h1' | 'h2' | 'h3';
};

const props = withDefaults(defineProps<Props>(), {
    variant: 'default',
    as: 'h2',
});

const titleClasses = computed(() => {
    switch (props.variant) {
        case 'small':
            return 'mb-0.5 font-mono text-label font-medium tracking-widest text-muted-foreground uppercase';
        case 'display':
            return 'font-display text-display font-extrabold leading-[0.95] tracking-tight text-balance';
        default:
            return 'font-display text-title font-bold tracking-tight';
    }
});
</script>

<template>
    <!--
        A `<div>`, not a `<header>`: §7.3 gives the AppLayout shell exactly one
        `<header>` (the sticky topbar), and HTML-AAM only exempts `article`,
        `aside`, `main`, `nav` and `section` from `header`'s `banner` role — so
        a `<header>` per heading is a second banner landmark wherever this
        component is used outside `<main>`. `PublicFooter.vue` already works
        around this by not using `Heading` for its column titles.
    -->
    <div :class="variant === 'small' ? '' : 'mb-8 space-y-0.5'">
        <!--
            §8.4 item 4 — Clarity runs in Strict mode, so the whole page is
            masked unless it is explicitly unmasked. The page `<h1>` is the one
            heading that is always public content; section titles stay masked,
            and an admin `<h1>` is never recorded because Clarity is not loaded
            on `/admin` at all.
        -->
        <component
            :is="as"
            :class="titleClasses"
            :data-clarity-unmask="as === 'h1' ? 'true' : undefined"
        >
            {{ title }}
        </component>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </div>
</template>
