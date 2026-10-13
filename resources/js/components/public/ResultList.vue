<script setup lang="ts">
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import ContentCard from '@/components/public/ContentCard.vue';
import type { ContentType, Tag } from '@/lib/contentTypes';

/**
 * The shape every public list controller builds for one item. It is declared here
 * rather than imported so the list can accept the `unknown[]` it is handed
 * without the pages having to share a type module.
 */
type CardItem = {
    title: string;
    summary?: string | null;
    href?: string | null;
    meta?: { label: string; value: string }[];
    tags?: Tag[];
    type?: ContentType;
    isPlaceholder?: boolean;
};

type Props = {
    items: unknown[];
    emptyTitle: string;
    emptyDescription?: string;
    /**
     * The heading level each card's title takes. A list directly under the page
     * `<h1>` uses the default; a list inside a section that already has an `<h2>`
     * (a tag group, a home-page column) passes `h3`, and a list inside a column
     * whose own heading is an `<h3>` passes `h4`, so no level is skipped (§7.4).
     */
    headingLevel?: 'h2' | 'h3' | 'h4';
};

const props = withDefaults(defineProps<Props>(), {
    emptyDescription: undefined,
    headingLevel: 'h2',
});

const cards = computed(() => props.items as CardItem[]);
</script>

<template>
    <div>
        <EmptyState
            v-if="cards.length === 0"
            :title="emptyTitle"
            :description="emptyDescription"
        />

        <ul v-else class="space-y-4">
            <li
                v-for="(item, index) in cards"
                :key="item.href ?? `${item.title}-${index}`"
            >
                <ContentCard
                    :title="item.title"
                    :summary="item.summary"
                    :href="item.href"
                    :meta="item.meta"
                    :tags="item.tags"
                    :type="item.type"
                    :is-placeholder="item.isPlaceholder"
                    :heading-level="headingLevel"
                />
            </li>
        </ul>
    </div>
</template>
