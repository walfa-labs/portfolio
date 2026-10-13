<script setup lang="ts">
/*
 * §3.1.2 — the `admin/` branch of the resolver returns `[AppLayout, AdminLayout]`,
 * so this is the inner of two layouts and contributes no landmark of its own:
 * `AppLayout` already renders the one `<header>`, the one `<nav aria-label="Main">`
 * and the one `<main id="main-content" tabindex="-1">` §7.3 asks of the sidebar
 * shell. `resources/js/app.ts` mounts the single `RouteAnnouncer`, so a mount
 * here would be a second `role="status"` region — §7.3 allows exactly one.
 *
 * What it does contribute is §6.7's density: `text-sm` body copy for the whole
 * admin surface, stated once instead of on every page. It adds no gutter of its
 * own, so each page keeps control of its padding.
 *
 * The page props reach every layout in the array, so `inheritAttrs: false` keeps
 * `records`, `filters`, `meta`, `record` and `lastCreated` out of the DOM.
 */
defineOptions({ inheritAttrs: false });
</script>

<template>
    <div class="flex min-w-0 flex-1 flex-col text-sm">
        <slot />
    </div>
</template>
