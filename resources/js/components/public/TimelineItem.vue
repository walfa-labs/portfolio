<script setup lang="ts">
import PlaceholderBadge from '@/components/public/PlaceholderBadge.vue';
import TagChip from '@/components/TagChip.vue';
import type { Tag } from '@/lib/contentTypes';

type Props = {
    title: string;
    employer: string;
    employerUrl?: string | null;
    location?: string | null;
    startDate: string;
    endDate?: string | null;
    summary?: string | null;
    highlights?: string[];
    tags?: Tag[];
    isPlaceholder?: boolean;
};

const {
    title,
    employer,
    employerUrl = null,
    location = null,
    startDate,
    endDate = null,
    summary = null,
    highlights = [],
    tags = [],
    isPlaceholder = false,
} = defineProps<Props>();

const formatMonth = (value: string): string => {
    const [year, month] = value.split('-');

    return new Date(
        Date.UTC(Number(year), Number(month) - 1, 1),
    ).toLocaleDateString('en', {
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });
};
</script>

<template>
    <li class="relative border-l-2 border-ink pl-6 sm:pl-8">
        <span
            aria-hidden="true"
            class="absolute top-6 -left-[7px] size-3 rounded-full border-2 border-ink bg-tone-viridian"
        />

        <article
            class="rounded-md border-2 border-ink bg-card p-6 shadow-hard-sm"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <h2
                    class="font-display text-xl font-bold tracking-tight text-balance"
                >
                    {{ title }}
                </h2>
                <PlaceholderBadge :show="isPlaceholder" />
            </div>

            <p class="mt-1 text-sm font-medium">
                <a
                    v-if="employerUrl"
                    :href="employerUrl"
                    rel="noopener noreferrer"
                    class="underline decoration-2 underline-offset-4 hover:text-primary"
                >
                    {{ employer }}
                </a>
                <span v-else>{{ employer }}</span>
                <span v-if="location" class="text-muted-foreground">
                    · {{ location }}
                </span>
            </p>

            <p
                class="mt-2 font-mono text-label tracking-widest text-muted-foreground uppercase"
            >
                <time :datetime="startDate">{{ formatMonth(startDate) }}</time>
                <span aria-hidden="true"> – </span>
                <template v-if="endDate">
                    <time :datetime="endDate">{{ formatMonth(endDate) }}</time>
                </template>
                <template v-else>Present</template>
            </p>

            <p v-if="summary" class="mt-4 text-sm text-muted-foreground">
                {{ summary }}
            </p>

            <ul v-if="highlights.length" class="mt-4 space-y-1.5 pl-5 text-sm">
                <li
                    v-for="highlight in highlights"
                    :key="highlight"
                    class="list-disc"
                >
                    {{ highlight }}
                </li>
            </ul>

            <ul v-if="tags.length" class="mt-5 flex flex-wrap gap-2">
                <li v-for="tag in tags" :key="tag.slug">
                    <TagChip :tag="tag" />
                </li>
            </ul>
        </article>
    </li>
</template>
