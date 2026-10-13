<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/public/Pagination.vue';
import SeoHead from '@/components/public/SeoHead.vue';
import TimelineItem from '@/components/public/TimelineItem.vue';
import type { Tag } from '@/lib/contentTypes';

type TimelineEntry = {
    id: number;
    title: string;
    employer: string;
    employerUrl: string | null;
    location: string | null;
    startDate: string;
    endDate: string | null;
    summary: string | null;
    highlights: string[];
    tags: Tag[];
    isPlaceholder: boolean;
};

type Props = {
    experiences: {
        data: TimelineEntry[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    title: string;
    description: string;
    canonical: string;
    jsonLd: Record<string, unknown>[];
    noindex: boolean;
    robots?: string | null;
};

defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: '/' },
            { title: 'Experience', href: '/experience' },
        ],
    },
});
</script>

<template>
    <SeoHead
        :title="title"
        :description="description"
        :canonical="canonical"
        :json-ld="jsonLd"
        :noindex="noindex"
        :robots="robots"
    />

    <div class="space-y-8">
        <Heading as="h1" title="Experience" :description="description" />

        <EmptyState
            v-if="experiences.data.length === 0"
            title="No roles published yet"
            description="Work experience entries appear here once they are published."
        />

        <template v-else>
            <ul class="space-y-6">
                <TimelineItem
                    v-for="entry in experiences.data"
                    :key="entry.id"
                    :title="entry.title"
                    :employer="entry.employer"
                    :employer-url="entry.employerUrl"
                    :location="entry.location"
                    :start-date="entry.startDate"
                    :end-date="entry.endDate"
                    :summary="entry.summary"
                    :highlights="entry.highlights"
                    :tags="entry.tags"
                    :is-placeholder="entry.isPlaceholder"
                />
            </ul>

            <Pagination
                v-if="experiences.data.length"
                :links="experiences.links"
                :meta="experiences"
            />
        </template>
    </div>
</template>
