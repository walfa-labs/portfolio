<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { computed } from 'vue';
import WritingAdSlot from '@/components/ads/WritingAdSlot.vue';
import Heading from '@/components/Heading.vue';
import PlaceholderBadge from '@/components/public/PlaceholderBadge.vue';
import SeoHead from '@/components/public/SeoHead.vue';
import TagChip from '@/components/TagChip.vue';
import type { Tag } from '@/lib/contentTypes';

type WritingCoverVariant = {
    url: string;
    width: number;
    height: number;
    mime: string;
};

type Props = {
    writing: {
        title: string;
        slug: string;
        excerpt: string | null;
        bodyHtml: string | null;
        publishedAt: string | null;
        publishedLabel: string;
        updatedAt: string | null;
        readingTime: number | null;
        series: string | null;
        seriesSlug: string | null;
        tags: Tag[];
        isPlaceholder: boolean;
        image: string | null;
        coverVariants: Record<string, WritingCoverVariant> | null;
    };
    title: string;
    description: string;
    canonical: string;
    jsonLd: Record<string, unknown>[];
    noindex: boolean;
};

const props = defineProps<Props>();

/*
 * The displayed `og:image` prefers the `1200×630` cover variant — the size
 * §9.5 generates for the Open Graph card — over the client-supplied
 * `og_image_path` string, which stays the API's write contract unchanged
 * (pinned decision 16). The format order is the serving route's own fallback
 * order: avif → webp → jpg → png. With neither a variant nor a path, `null`
 * lets `SeoHead` fall back to the committed `og/default.png` placeholder.
 */
const coverOgImage = computed(() => {
    const variants = props.writing.coverVariants;

    if (variants === null) {
        return null;
    }

    for (const format of ['avif', 'webp', 'jpg', 'png']) {
        const variant = variants[`cover_1200x630.${format}`];

        if (variant !== undefined) {
            return variant.url;
        }
    }

    return null;
});

const ogImage = computed(
    () => coverOgImage.value ?? props.writing.image ?? undefined,
);

/*
 * The breadcrumb trail carries the post title, so it cannot be declared
 * statically with `defineOptions({ layout })` the way the index pages do.
 */
setLayoutProps({
    breadcrumbs: [
        { title: 'Home', href: '/' },
        { title: 'Writing', href: '/writing' },
        { title: props.writing.title, href: `/writing/${props.writing.slug}` },
    ],
});
</script>

<template>
    <SeoHead
        :title="title"
        :description="description"
        :canonical="canonical"
        :image="ogImage"
        type="article"
        :json-ld="jsonLd"
        :noindex="noindex"
    />

    <!--
        `SeoHead`'s pinned prop list has no slot for the article metadata §3.9
        requires on an article page, so the three `article:*` families are
        emitted here, each with its own `head-key` so Inertia de-duplicates them.
    -->
    <Head>
        <meta
            v-if="writing.publishedAt"
            head-key="article:published_time"
            property="article:published_time"
            :content="writing.publishedAt"
        />
        <meta
            v-if="writing.updatedAt"
            head-key="article:modified_time"
            property="article:modified_time"
            :content="writing.updatedAt"
        />
        <meta
            v-for="(tag, index) in writing.tags"
            :key="tag.slug"
            :head-key="`article:tag-${index}`"
            property="article:tag"
            :content="tag.name"
        />
    </Head>

    <article class="space-y-10">
        <header class="space-y-4">
            <Heading as="h1" variant="display" :title="writing.title" />

            <PlaceholderBadge :show="writing.isPlaceholder" />

            <p
                class="flex flex-wrap items-center gap-x-4 gap-y-2 text-meta text-muted-foreground"
            >
                <span v-if="writing.publishedAt">
                    Published
                    <time :datetime="writing.publishedAt">{{
                        writing.publishedLabel
                    }}</time>
                </span>
                <span v-if="writing.readingTime">
                    {{ writing.readingTime }} min read
                </span>
                <span v-if="writing.series">
                    Part of the {{ writing.series }} series
                </span>
            </p>
        </header>

        <div
            v-if="writing.bodyHtml"
            class="writing-body"
            v-html="writing.bodyHtml"
        />

        <p v-else class="max-w-[60ch] text-muted-foreground">
            This piece has no body yet.
        </p>

        <!--
            The only mount point for the ad slot in the whole application (§8.5
            item 1): blog detail pages and nothing else. It renders nothing at
            all unless `privacy.adsAllowed` is true, so the blog list, tag pages
            and every other route have no code path that can reach Google. Below
            the article body, so it is never above the fold.
        -->
        <WritingAdSlot />

        <section v-if="writing.tags.length" class="space-y-3">
            <Heading as="h2" variant="small" title="Tags" />

            <ul class="flex flex-wrap gap-2">
                <li v-for="tag in writing.tags" :key="tag.slug">
                    <TagChip :tag="tag" />
                </li>
            </ul>
        </section>
    </article>
</template>

<!--
    The Markdown body is rendered server-side (`writings.body_html`) and Tailwind
    Typography is not installed, so the prose elements are styled here, from the
    same tokens as the rest of the site. Scoped to this page only.
-->
<style scoped>
.writing-body {
    max-width: 70ch;
}

.writing-body :deep(p) {
    margin-block: 1.25rem;
    line-height: 1.75;
}

.writing-body :deep(h2) {
    margin-block: 2.5rem 0.75rem;
    font-family: var(--font-display);
    font-size: 1.75rem;
    font-weight: 700;
    line-height: 1.15;
    letter-spacing: -0.01em;
}

.writing-body :deep(h3) {
    margin-block: 2rem 0.5rem;
    font-family: var(--font-display);
    font-size: 1.25rem;
    font-weight: 700;
}

.writing-body :deep(h4) {
    margin-block: 1.5rem 0.5rem;
    font-weight: 700;
}

.writing-body :deep(ul),
.writing-body :deep(ol) {
    margin-block: 1.25rem;
    padding-inline-start: 1.5rem;
}

.writing-body :deep(ul) {
    list-style: square;
}

.writing-body :deep(ol) {
    list-style: decimal;
}

.writing-body :deep(li) {
    margin-block: 0.5rem;
    line-height: 1.7;
}

.writing-body :deep(a) {
    color: var(--primary);
    text-decoration: underline;
    text-decoration-thickness: 2px;
    text-underline-offset: 4px;
}

.writing-body :deep(a:hover) {
    color: var(--primary-hover);
}

.writing-body :deep(strong) {
    font-weight: 700;
}

.writing-body :deep(blockquote) {
    margin-block: 2rem;
    border-inline-start: 4px solid var(--border);
    padding-inline-start: 1.25rem;
    color: var(--muted-foreground);
}

.writing-body :deep(code) {
    font-family: var(--font-mono);
    font-size: 0.875em;
    background-color: var(--secondary);
    border: 2px solid var(--border);
    border-radius: 2px;
    padding: 0.1em 0.3em;
}

.writing-body :deep(pre) {
    margin-block: 2rem;
    overflow-x: auto;
    background-color: var(--card);
    border: 2px solid var(--border);
    border-radius: 3px;
    padding: 1rem;
    /*
     * The `pre` is a scroll container for a code block wider than the column,
     * so it must be reachable by keyboard: `FocusableCodeBlockExtension`
     * renders it with `tabindex="0"` and the code is never wrapped (§7.2 /
     * WCAG 2.1.1). Wrapping would remove the scroll region but break long
     * lines mid-token, which is the worse trade for a code sample.
     */
}

.writing-body :deep(pre code) {
    background-color: transparent;
    border: 0;
    padding: 0;
    font-size: 0.8125rem;
    line-height: 1.6;
}

.writing-body :deep(hr) {
    margin-block: 2.5rem;
    border: 0;
    border-top: 2px solid var(--border);
}

.writing-body :deep(img) {
    max-width: 100%;
    height: auto;
    border: 2px solid var(--border);
    border-radius: 3px;
}

.writing-body :deep(table) {
    width: 100%;
    margin-block: 2rem;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.writing-body :deep(th),
.writing-body :deep(td) {
    border: 2px solid var(--border);
    padding: 0.5rem 0.75rem;
    text-align: start;
}

.writing-body :deep(th) {
    background-color: var(--secondary);
    font-weight: 700;
}

.writing-body :deep(> :first-child) {
    margin-block-start: 0;
}
</style>
