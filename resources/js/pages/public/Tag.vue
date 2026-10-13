<script setup lang="ts">
import { setLayoutProps } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/public/Pagination.vue';
import PlaceholderBadge from '@/components/public/PlaceholderBadge.vue';
import ResultList from '@/components/public/ResultList.vue';
import SeoHead from '@/components/public/SeoHead.vue';
import {
    contentTypeLabels,
    type ContentType,
    type Tag,
} from '@/lib/contentTypes';

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
    tag: {
        name: string;
        slug: string;
        description: string | null;
        isPlaceholder: boolean;
    };
    items: {
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

/*
 * The fixed group order §3.7 requires: experience → education → certifications
 * → publications → projects → awards → writing. It is the declaration order of
 * `ContentType`, so the server and the page cannot disagree about it.
 */
const GROUP_ORDER: ContentType[] = [
    'work_experience',
    'education',
    'certification',
    'publication',
    'project',
    'award',
    'writing',
];

const groups = computed(() =>
    GROUP_ORDER.map((type) => ({
        type,
        label: contentTypeLabels[type],
        items: props.items.data.filter((item) => item.type === type),
    })).filter((group) => group.items.length > 0),
);

setLayoutProps({
    breadcrumbs: [
        { title: 'Home', href: '/' },
        { title: 'Tags', href: '/tags' },
        { title: `#${props.tag.slug}`, href: `/tags/${props.tag.slug}` },
    ],
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

    <div class="space-y-10">
        <div class="space-y-4">
            <!--
                The `<h1>` is the tag's canonical handle, so it agrees with the
                `<title>`; the display name is the lede (§7.3).
            -->
            <Heading
                as="h1"
                variant="display"
                :title="`#${tag.slug}`"
                :description="tag.name"
            />

            <PlaceholderBadge :show="tag.isPlaceholder" />

            <p v-if="tag.description" class="max-w-[60ch]">
                {{ tag.description }}
            </p>
        </div>

        <template v-if="groups.length">
            <section
                v-for="group in groups"
                :key="group.type"
                class="space-y-6"
            >
                <Heading as="h2" :title="group.label" />

                <ResultList
                    :items="group.items"
                    :empty-title="`Nothing tagged #${tag.slug} in ${group.label.toLowerCase()}`"
                    heading-level="h3"
                />
            </section>

            <Pagination :links="items.links" :meta="items" />
        </template>

        <EmptyState
            v-else
            :title="`Nothing tagged #${tag.slug} yet`"
            description="Items carrying this tag appear here once they are published."
        />
    </div>
</template>
