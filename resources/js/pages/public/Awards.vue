<script setup lang="ts">
import Heading from '@/components/Heading.vue';
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
    awards: CardItem[];
    title: string;
    description: string;
    canonical: string;
    jsonLd: Record<string, unknown>[];
    noindex: boolean;
};

defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: '/' },
            { title: 'Awards', href: '/awards' },
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
    />

    <div class="space-y-8">
        <Heading as="h1" title="Awards" :description="description" />

        <ResultList
            :items="awards"
            empty-title="No awards published yet"
            empty-description="Awards and recognitions appear here once they are published."
        />
    </div>
</template>
