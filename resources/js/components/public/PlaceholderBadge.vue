<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Badge, badgeVariants } from '@/components/ui/badge';

/*
 * `show` needs an explicit `undefined` default: a `boolean` prop with no default
 * is absent-cast to `false` by Vue, which would silently kill the fallback below
 * for every caller that omits the prop.
 */
const props = withDefaults(
    defineProps<{
        show?: boolean;
    }>(),
    {
        show: undefined,
    },
);

const page = usePage();

/*
 * §3.1 item 5 / §13 R9 — the badge renders when the row is a placeholder **and**
 * the environment is not production. The row half arrives as `show`; the
 * environment half is the shared `placeholderBadgeEnabled` prop, which a caller
 * that says nothing falls back to.
 */
const visible = computed(
    () => (props.show ?? true) && page.props.placeholderBadgeEnabled === true,
);
</script>

<template>
    <!--
        `tone` is the intended API (§6.5), but `ui/badge/Badge.vue` still forwards
        only `variant` to the cva, so the tone axis is applied through `class` as
        well. Drop the `:class` once that component passes `tone` through.
    -->
    <Badge
        v-if="visible"
        tone="marigold"
        :class="badgeVariants({ tone: 'marigold' })"
    >
        Placeholder
    </Badge>
</template>
