<script setup lang="ts">
import { computed } from 'vue';
import ContentTypeBadge from '@/components/ContentTypeBadge.vue';
import LinkCard from '@/components/LinkCard.vue';
import PlaceholderBadge from '@/components/public/PlaceholderBadge.vue';
import TagChip from '@/components/TagChip.vue';
import type { ContentType, Tag } from '@/lib/contentTypes';

type Props = {
    title: string;
    summary?: string | null;
    href?: string | null;
    meta?: { label: string; value: string }[];
    tags?: Tag[];
    type?: ContentType;
    isPlaceholder?: boolean;
    headingLevel?: 'h2' | 'h3' | 'h4';
};

const props = withDefaults(defineProps<Props>(), {
    summary: null,
    href: null,
    meta: () => [],
    tags: () => [],
    type: undefined,
    isPlaceholder: false,
    headingLevel: 'h2',
});

/*
 * The resting card surface for an item with no destination. It is `LinkCard`'s
 * own look minus the press-lift: there is nothing to focus, and §7.5(3) only
 * allows a translation on the element that carries the focus indicator.
 */
const CARD_SURFACE =
    'rounded-md border-2 border-ink bg-card p-6 shadow-hard-sm';

/*
 * `href` is nullable in the content model — a role with no employer URL, an award
 * with no awarding body link — and an `<a href="">` would navigate to the current
 * page, so a card without a destination renders as a plain surface instead.
 */
const container = computed(() => (props.href ? LinkCard : 'div'));

const containerProps = computed(() => (props.href ? { href: props.href } : {}));

const hasTags = computed(() => props.tags.length > 0);
</script>

<template>
    <!--
        The tag chips are siblings of the card link, not children of it: `TagChip`
        is a link to `/tags/{slug}` (§3.7) and a link inside a link is invalid
        markup that fires both navigations.
    -->
    <article :class="hasTags ? 'space-y-3' : undefined">
        <component
            :is="container"
            v-bind="containerProps"
            :class="href ? undefined : CARD_SURFACE"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <ContentTypeBadge v-if="type" :type="type" />
                <PlaceholderBadge :show="isPlaceholder" />
            </div>

            <!--
                §8.4 item 4 — card titles are public, non-personal content, so
                they are unmasked under Clarity's Strict mode; the summary,
                metadata and tags around them stay masked.
            -->
            <component
                :is="headingLevel"
                class="mt-3 font-display text-xl font-bold tracking-tight text-balance"
                data-clarity-unmask="true"
            >
                {{ title }}
            </component>

            <p v-if="summary" class="mt-2 text-sm text-muted-foreground">
                {{ summary }}
            </p>

            <dl v-if="meta.length" class="mt-4 space-y-1.5">
                <div
                    v-for="entry in meta"
                    :key="entry.label"
                    class="flex flex-wrap gap-x-2"
                >
                    <dt
                        class="font-mono text-label tracking-widest text-muted-foreground uppercase"
                    >
                        {{ entry.label }}
                    </dt>
                    <dd class="text-meta break-all">{{ entry.value }}</dd>
                </div>
            </dl>
        </component>

        <ul v-if="hasTags" class="flex flex-wrap gap-2">
            <li v-for="tag in tags" :key="tag.slug">
                <TagChip :tag="tag" />
            </li>
        </ul>
    </article>
</template>
