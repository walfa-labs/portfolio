<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { Badge, badgeVariants } from '@/components/ui/badge';
import type { Tag } from '@/lib/contentTypes';

const { tag, removable = false } = defineProps<{
    tag: Tag;
    removable?: boolean;
}>();

const emit = defineEmits<{
    remove: [tag: Tag];
}>();
</script>

<template>
    <!--
        `tone` is the intended API (§6.5), but `ui/badge/Badge.vue` still forwards only `variant` to
        the cva, so the tone axis is applied through `class` as well. Drop the `:class` once that
        component passes `tone` through.
    -->
    <Badge
        tone="neutral"
        :class="
            badgeVariants({
                tone: 'neutral',
                class: 'gap-0 py-1 pr-1 pl-2',
            })
        "
    >
        <Link
            :href="`/tags/${tag.slug}`"
            class="inline-flex min-h-6 min-w-6 items-center px-1"
        >
            {{ tag.name }}
        </Link>
        <button
            v-if="removable"
            type="button"
            class="inline-flex min-h-6 min-w-6 items-center justify-center"
            :aria-label="`Remove ${tag.name}`"
            @click="emit('remove', tag)"
        >
            <X class="size-3" aria-hidden="true" />
        </button>
    </Badge>
</template>
