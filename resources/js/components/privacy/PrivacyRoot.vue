<script setup lang="ts">
import ConsentBanner from '@/components/privacy/ConsentBanner.vue';

/*
 * §3.1.2 / §8.3 — `withPrivacy()` in `app.ts` mounts this around every layout
 * the resolver returns, so the banner reaches the auth and layout-less surfaces
 * as well as the public, admin and settings shells. It renders `<slot />` plus
 * the banner because a layout-less page would otherwise never show it.
 *
 * It is a two-node fragment that receives every page prop as an attribute, so
 * `inheritAttrs: false` is what stops Vue warning about attributes it cannot
 * fall through.
 */
defineOptions({ inheritAttrs: false });
</script>

<template>
    <slot />
    <ConsentBanner />
</template>
