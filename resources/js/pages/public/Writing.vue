<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/public/Pagination.vue';
import ResultList from '@/components/public/ResultList.vue';
import SeoHead from '@/components/public/SeoHead.vue';
import type { ContentType, Tag } from '@/lib/contentTypes';

type CardItem = {
    id: number;
    title: string;
    summary?: string | null;
    href?: string | null;
    meta?: { label: string; value: string }[];
    tags?: Tag[];
    type?: ContentType;
    isPlaceholder?: boolean;
};

type Props = {
    writings: {
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

defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: '/' },
            { title: 'Writing', href: '/writing' },
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
        <Heading as="h1" title="Writing" :description="description" />

        <ResultList
            :items="writings.data"
            empty-title="No writing published yet"
            empty-description="Notes, walkthroughs and post-mortems appear here once the first piece is published."
        />

        <Pagination
            v-if="writings.data.length"
            :links="writings.links"
            :meta="writings"
        />
    </div>
</template>
