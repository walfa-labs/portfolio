<script setup lang="ts">
import { Badge, badgeVariants } from '@/components/ui/badge';
import { contentTypeLabels, contentTypeTones } from '@/lib/contentTypes';
import type { ContentType } from '@/lib/contentTypes';

const { type } = defineProps<{
    type: ContentType;
}>();
</script>

<template>
    <!--
        `tone` is the intended API (§6.5), but `ui/badge/Badge.vue` still forwards only `variant` to
        the cva, so the tone axis is applied through `class` as well. Drop the `:class` once that
        component passes `tone` through.
    -->
    <Badge
        :tone="contentTypeTones[type]"
        :class="badgeVariants({ tone: contentTypeTones[type] })"
    >
        {{ contentTypeLabels[type] }}
    </Badge>
</template>
