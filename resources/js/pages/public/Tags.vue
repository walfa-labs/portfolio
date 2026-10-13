<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import SeoHead from '@/components/public/SeoHead.vue';
import TagChip from '@/components/TagChip.vue';
import { Badge, badgeVariants } from '@/components/ui/badge';
import type { Tag } from '@/lib/contentTypes';
import { show as tagShow } from '@/routes/tags';

type TagWithCount = Tag & {
    count: number;
    isPlaceholder: boolean;
};

type Props = {
    tags: TagWithCount[];
    title: string;
    description: string;
    canonical: string;
    jsonLd: Record<string, unknown>[];
    noindex: boolean;
};

const props = defineProps<Props>();

/* §3.4 — every page except `/` carries a trail. */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: '/' },
            { title: 'Tags', href: '/tags' },
        ],
    },
});

/** The heaviest tag sets the top of the size ramp (§3.7). */
const maxCount = computed(() =>
    props.tags.reduce((max, tag) => Math.max(max, tag.count), 0),
);

/*
 * The weight is applied as an inline font size rather than a utility class: the
 * `Badge` sets its own `text-label` size, and an inline style is the one thing
 * that reliably wins over it.
 */
const cloudSize = (count: number): string => {
    if (maxCount.value === 0) {
        return '0.8125rem';
    }

    const ratio = count / maxCount.value;

    if (ratio >= 0.67) {
        return '1.25rem';
    }

    if (ratio >= 0.34) {
        return '1rem';
    }

    return '0.8125rem';
};

/*
 * The controller sends the cloud order (count desc, name asc). The A–Z list
 * re-sorts on the lower-cased name, which is the ASCII-normalised ordering the
 * server uses, so the two never disagree about what "alphabetical" means.
 */
const alphabetical = computed(() =>
    [...props.tags].sort((a, b) => {
        const left = a.name.toLowerCase();
        const right = b.name.toLowerCase();

        return left < right ? -1 : left > right ? 1 : 0;
    }),
);

const itemLabel = (count: number): string =>
    `${count} ${count === 1 ? 'item' : 'items'}`;
</script>

<template>
    <SeoHead
        :title="title"
        :description="description"
        :canonical="canonical"
        :json-ld="jsonLd"
        :noindex="noindex"
    />

    <div class="space-y-12">
        <Heading as="h1" title="Tags" :description="description" />

        <nav v-if="tags.length" aria-label="Filter by tag" class="space-y-4">
            <ul class="flex flex-wrap items-center gap-3">
                <li v-for="tag in tags" :key="tag.slug">
                    <!--
                        `ui/badge/Badge.vue` does not forward the `tone` axis to
                        the cva yet, so the tone is applied through `class` — the
                        same workaround `TagChip.vue` uses.
                    -->
                    <Badge
                        :class="
                            badgeVariants({
                                tone: 'neutral',
                                class: 'gap-1 py-1 pr-2 pl-2',
                            })
                        "
                        :style="{ fontSize: cloudSize(tag.count) }"
                    >
                        <Link
                            :href="tagShow(tag.slug)"
                            class="inline-flex min-h-6 min-w-6 items-center gap-1.5 px-1"
                        >
                            <span>{{ tag.name }}</span>
                            <span
                                class="text-muted-foreground tabular-nums"
                                :style="{ fontSize: '0.6875rem' }"
                                >{{ tag.count }}</span
                            >
                        </Link>
                    </Badge>
                </li>
            </ul>
        </nav>

        <section v-if="tags.length" class="space-y-4">
            <Heading as="h2" variant="small" title="All tags A–Z" />

            <ul class="flex flex-wrap gap-x-6 gap-y-3">
                <li
                    v-for="tag in alphabetical"
                    :key="tag.slug"
                    class="flex items-center gap-2"
                >
                    <TagChip :tag="tag" />
                    <span
                        class="font-mono text-label text-muted-foreground tabular-nums"
                        >{{ itemLabel(tag.count) }}</span
                    >
                </li>
            </ul>
        </section>

        <EmptyState
            v-else
            title="No tags yet"
            description="Tags appear here as soon as content carries one."
        />
    </div>
</template>
