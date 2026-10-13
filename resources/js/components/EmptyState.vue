<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Button } from '@/components/ui/button';

const { icon, title, description, actionLabel, actionHref } = defineProps<{
    icon?: LucideIcon;
    title: string;
    description?: string;
    actionLabel?: string;
    actionHref?: NonNullable<InertiaLinkProps['href']>;
}>();

const emit = defineEmits<{
    action: [];
}>();
</script>

<template>
    <div
        class="rounded-md border-2 border-ink bg-card p-8 text-center shadow-hard-sm md:p-12"
    >
        <div
            class="relative mx-auto mb-6 flex size-14 items-center justify-center overflow-hidden rounded-sm border-2 border-ink bg-muted"
        >
            <component
                :is="icon"
                v-if="icon"
                class="size-6 text-ink"
                aria-hidden="true"
            />
            <PlaceholderPattern v-else />
        </div>

        <Heading variant="default" :title="title" />

        <p
            v-if="description"
            class="mx-auto max-w-[45ch] text-muted-foreground"
        >
            {{ description }}
        </p>

        <Button
            v-if="actionLabel && actionHref"
            as-child
            size="lg"
            class="mt-6"
        >
            <Link :href="actionHref">{{ actionLabel }}</Link>
        </Button>
        <Button
            v-else-if="actionLabel"
            type="button"
            size="lg"
            class="mt-6"
            @click="emit('action')"
        >
            {{ actionLabel }}
        </Button>
    </div>
</template>
