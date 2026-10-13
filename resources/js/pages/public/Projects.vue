<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/public/Pagination.vue';
import ResultList from '@/components/public/ResultList.vue';
import SeoHead from '@/components/public/SeoHead.vue';
import type { ContentType, Tag } from '@/lib/contentTypes';

type CardItem = {
    title: string;
    summary?: string | null;
    href: string;
    meta?: { label: string; value: string }[];
    tags?: Tag[];
    type?: ContentType;
    isPlaceholder?: boolean;
};

type Props = {
    projects: {
        data: CardItem[];
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

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: '/' },
            { title: 'Projects', href: '/projects' },
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
        <Heading as="h1" title="Projects" :description="description" />

        <ResultList
            :items="props.projects.data"
            empty-title="No projects published yet"
            empty-description="Software projects appear here once they are published."
        />

        <Pagination
            v-if="props.projects.last_page > 1"
            :links="props.projects.links"
            :meta="props.projects"
        />
    </div>
</template>
