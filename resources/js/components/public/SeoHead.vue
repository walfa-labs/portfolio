<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type Props = {
    title: string;
    description: string;
    canonical?: string;
    image?: string;
    type?: string;
    noindex?: boolean;
    /**
     * An explicit robots directive that wins over the `noindex` boolean.
     *
     * The boolean cannot express `noindex,follow`, which §3.1 item 6 requires on
     * page ≥ 2 of a paginated list and §3.8 requires on `/search`; those pages
     * send this string instead.
     */
    robots?: string | null;
    jsonLd?: object[];
    feedLink?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    canonical: undefined,
    image: undefined,
    type: 'website',
    noindex: false,
    robots: undefined,
    jsonLd: () => [],
    feedLink: true,
});

const page = usePage();

const shared = computed(() => page.props as Record<string, unknown>);

/**
 * The site name is the shared `config('app.name')` prop, never a hardcoded
 * string: the owner's real name is still an open question (§14), so every
 * consumer reads it from one place.
 */
const siteName = computed(() => String(shared.value.name ?? ''));

/*
 * Canonical URLs are built from `config('app.url')` and never from
 * `$_SERVER['HTTP_HOST']` (§3.9). The frontend has no `app.url` prop, so the
 * origin is read back off the canonical URL every public controller builds from
 * that config value.
 */
const siteOrigin = computed(() => {
    const match = /^(https?:\/\/[^/]+)/i.exec(props.canonical ?? '');

    return match === null ? '' : match[1];
});

/**
 * `writings.og_image_path` is a path relative to the site root and every other
 * image is already absolute, so both shapes are accepted (§3.9, §9.1).
 */
const absolute = (value: string): string => {
    if (/^[a-z][a-z0-9+.-]*:/i.test(value) || value.startsWith('//')) {
        return value;
    }

    return `${siteOrigin.value}/${value.replace(/^\/+/, '')}`;
};

const ogImage = computed(() => absolute(props.image ?? 'og/default.png'));

const ogUrl = computed(() => props.canonical ?? siteOrigin.value);

const feedUrl = computed(() => absolute('writing/feed.atom'));

/*
 * `og:locale` is required on every page and no shared prop carries the locale, so
 * it comes from the `<html lang>` the Blade shell sets from `app()->getLocale()`.
 * There is no document in SSR, where the application default is the answer.
 */
const ogLocale = computed(() => {
    const sharedLocale = shared.value.locale;
    const tag =
        typeof sharedLocale === 'string'
            ? sharedLocale
            : typeof document === 'undefined'
              ? ''
              : document.documentElement.lang;

    return (tag === '' ? 'en' : tag).replace('-', '_');
});

/*
 * §3.1 item 5: while the content is placeholder nothing is indexable, so every
 * page is `noindex,nofollow` when `config('app.indexable')` is false. §3.1 item 6
 * makes page ≥ 2 of a paginated list `noindex,follow`, and §3.8 makes `/search`
 * the same — two states the single `noindex` boolean cannot tell apart, so those
 * pages send the directive explicitly in the `robots` prop, which wins here.
 *
 * The `X-Robots-Tag` header on `/search` stays `noindex, nofollow` (§3.9): the
 * PRD pins the header and the page tag to different values, and that asymmetry is
 * deliberate — this component only owns the `<meta name="robots">` tag.
 */
const robots = computed(() =>
    props.robots ??
    (props.noindex || shared.value.indexable === false
        ? 'noindex,nofollow'
        : 'index,follow'),
);

/**
 * `<` is escaped so a document can never close the tag it is written into.
 */
const serialise = (document: object): string =>
    JSON.stringify(document).replace(/</g, '\\u003C');

/*
 * §3.9 asks for the graph as `<script type="application/ld+json">`, but Vue's
 * template compiler drops a literal `<script>` as a side-effect tag, so it cannot
 * be written as an element. Inertia's `<Head>` renders a text node verbatim,
 * which is how the tag reaches the document; `data-inertia` is written by hand
 * because it is the attribute Inertia de-duplicates head elements by — it is what
 * `head-key` compiles to.
 *
 * Each interpolation must stay adjacent to its `<template>` tag: whitespace
 * between them is merged into the string, and Inertia's renderer then no longer
 * recognises the element as a script.
 *
 * The closing tag is assembled from a constant rather than written out: a literal
 * closing script tag anywhere in this block would end the SFC section that holds
 * it, because the parser finds it before any JavaScript does.
 */
const TAG_NAME = 'script';

const scripts = computed(() =>
    props.jsonLd.map(
        (document, index) =>
            `<script type="application/ld+json" data-inertia="ld-json-${index}">${serialise(document)}</${TAG_NAME}>`,
    ),
);
</script>

<template>
    <!--
        Every tag carries a stable `head-key`, which Inertia turns into
        `data-inertia` and uses to replace rather than duplicate an element across
        visits. A tag without one is keyed by position and accumulates.
    -->
    <Head>
        <title head-key="title">{{ title }}</title>
        <meta
            head-key="description"
            name="description"
            :content="description"
        />
        <link
            v-if="canonical"
            head-key="canonical"
            rel="canonical"
            :href="canonical"
        />
        <meta head-key="robots" name="robots" :content="robots" />

        <meta head-key="og:type" property="og:type" :content="type" />
        <meta head-key="og:title" property="og:title" :content="title" />
        <meta
            head-key="og:description"
            property="og:description"
            :content="description"
        />
        <meta head-key="og:url" property="og:url" :content="ogUrl" />
        <meta head-key="og:image" property="og:image" :content="ogImage" />
        <meta
            head-key="og:image:width"
            property="og:image:width"
            content="1200"
        />
        <meta
            head-key="og:image:height"
            property="og:image:height"
            content="630"
        />
        <meta
            head-key="og:site_name"
            property="og:site_name"
            :content="siteName"
        />
        <meta head-key="og:locale" property="og:locale" :content="ogLocale" />

        <meta
            head-key="twitter:card"
            name="twitter:card"
            content="summary_large_image"
        />
        <meta head-key="twitter:title" name="twitter:title" :content="title" />
        <meta
            head-key="twitter:description"
            name="twitter:description"
            :content="description"
        />
        <meta
            head-key="twitter:image"
            name="twitter:image"
            :content="ogImage"
        />

        <link
            v-if="feedLink"
            head-key="feed"
            rel="alternate"
            type="application/atom+xml"
            :title="`${siteName} — Writing`"
            :href="feedUrl"
        />

        <template v-for="(s, i) in scripts" :key="i">{{ s }}</template>
    </Head>
</template>
