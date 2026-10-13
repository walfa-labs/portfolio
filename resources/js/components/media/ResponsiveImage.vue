<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef } from 'vue';

/*
 * §9.7 — the one image renderer. Every image on the public site goes through
 * it, so `loading`, `decoding`, the reserved box, the LQIP and the `alt` rule
 * exist once rather than at each call site.
 *
 * One entry of a media row's `variants` map (§9.2, §9.10). The key is the
 * variant name *including* its extension — `cover_800x450.webp` — which is
 * exactly the `{variant}` segment of the serving route, and the dimensions are
 * what let the box be reserved before any bytes arrive.
 */
type MediaVariant = {
    url: string;
    width: number;
    height: number;
    mime?: string;
};

type Format = 'avif' | 'webp' | 'fallback';

type Source = {
    format: 'avif' | 'webp';
    srcset: string;
};

type Props = {
    /*
     * The variants map. An absent, empty or unprocessable map renders nothing:
     * whether an item without an image shows a placeholder, a monogram or
     * nothing at all is the caller's decision, not the renderer's.
     */
    media?: Record<string, MediaVariant> | null;
    /*
     * The authored alternative text (§7.8). `''` is a deliberate, reviewed
     * "decorative" decision; `null` (or omission) means no authored alt was
     * recorded, which §9.2 permits only for the three collections that have a
     * deterministic fallback — `logo`, `badge` and `avatar`.
     */
    alt?: string | null;
    /* The `sizes` the caller's layout gives the image, e.g. `(min-width: 768px) 45ch, 100vw`. */
    sizes?: string;
    /* The LCP image. Everything else is lazy (§9.7). */
    eager?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    media: null,
    alt: null,
    sizes: '100vw',
    eager: false,
});

/*
 * §9.5 stores the low-quality image placeholder under the `lqip` variant key,
 * beside the sizes rather than inside them, so it is never a `srcset` candidate
 * and never a `src`.
 */
const isLqip = (key: string): boolean => key.toLowerCase().startsWith('lqip');

const hasUrl = (variant: MediaVariant | undefined): boolean =>
    typeof variant?.url === 'string' && variant.url !== '';

const entries = computed<[string, MediaVariant][]>(() =>
    Object.entries(props.media ?? {}).filter(
        ([key, variant]) => !isLqip(key) && hasUrl(variant),
    ),
);

/*
 * A variant's format is its MIME type when the pipeline recorded one and its
 * extension otherwise; §9.5's keys always carry the extension. Anything that is
 * neither AVIF nor WebP — the JPEG/PNG fallback, and an SVG logo — is the
 * fallback bucket, which is the one the `<img>` element uses.
 */
const formatOf = ([key, variant]: [string, MediaVariant]): Format => {
    const mime = variant.mime ?? '';

    if (mime === 'image/avif' || key.endsWith('.avif')) {
        return 'avif';
    }

    if (mime === 'image/webp' || key.endsWith('.webp')) {
        return 'webp';
    }

    return 'fallback';
};

const grouped = computed<Record<Format, [string, MediaVariant][]>>(() => {
    const groups: Record<Format, [string, MediaVariant][]> = {
        avif: [],
        webp: [],
        fallback: [],
    };

    for (const entry of entries.value) {
        groups[formatOf(entry)].push(entry);
    }

    for (const group of Object.values(groups)) {
        group.sort((left, right) => left[1].width - right[1].width);
    }

    return groups;
});

/*
 * §9.7 asks for a `srcset` carrying the 1x and 2x candidates from the variant
 * map, beside the caller's `sizes`. Those two requirements are only
 * simultaneously satisfiable with **width** descriptors: with `x` descriptors
 * the browser ignores `sizes` completely, so the caller's layout would have no
 * say in which file is fetched and the smallest variant in the matrix could
 * never be chosen. Every width in the matrix is listed, and the browser derives
 * both its 1x and its 2x candidate from them using `sizes`.
 *
 * A candidate with no recorded width (an SVG logo, which is not rasterised)
 * cannot carry a width descriptor and cannot be mixed with one, so it is left
 * out of `srcset` and reached through `src` alone.
 */
const srcsetFor = (group: [string, MediaVariant][]): string =>
    group
        .filter(([, variant]) => variant.width > 0)
        .map(([, variant]) => `${variant.url} ${variant.width}w`)
        .join(', ');

const sources = computed<Source[]>(() => {
    const list: Source[] = [];

    for (const format of ['avif', 'webp'] as const) {
        const srcset = srcsetFor(grouped.value[format]);

        if (srcset !== '') {
            list.push({ format, srcset });
        }
    }

    return list;
});

/*
 * The `<img>` element is the fallback every `<source>` outranks, so it is the
 * most compatible encoding available: the §9.5 fallback format (JPEG, or PNG
 * when the source has transparency), then WebP, then AVIF. It is also the only
 * element that renders at all when the browser matches no `<source>`.
 */
const imgGroup = computed<[string, MediaVariant][]>(() => {
    const groups = grouped.value;

    if (groups.fallback.length > 0) {
        return groups.fallback;
    }

    return groups.webp.length > 0 ? groups.webp : groups.avif;
});

const imgVariant = computed<MediaVariant | null>(
    () => imgGroup.value.at(-1)?.[1] ?? null,
);

const imgSrcset = computed(() => srcsetFor(imgGroup.value));

/*
 * §9.7's reserved box: the chosen variant's own ratio, expressed as the
 * `aspect-ratio` it is, so the box is right for a 16:9 cover, a square avatar
 * and a screenshot of any shape without the caller declaring a ratio. A
 * Tailwind class cannot be built from data — the compiler only sees literals —
 * so this is the one inline style the component owns.
 */
const ratio = computed<string | null>(() => {
    const variant = imgVariant.value;

    return variant !== null && variant.width > 0 && variant.height > 0
        ? `${variant.width} / ${variant.height}`
        : null;
});

const lqip = computed<string | null>(() => {
    for (const [key, variant] of Object.entries(props.media ?? {})) {
        if (isLqip(key) && hasUrl(variant)) {
            return variant.url;
        }
    }

    return null;
});

/*
 * §9.7 allows `rounded-full` for avatars and nothing else. The component has no
 * collection prop, and §9.5's keys embed their collection — `avatar_512x512.avif`
 * — so the map itself says which one this is.
 */
const isAvatar = computed(() =>
    entries.value.some(([key]) => key.toLowerCase().startsWith('avatar')),
);

const loaded = ref(false);
const image = useTemplateRef<HTMLImageElement>('image');

/*
 * An image served from cache can finish before hydration, and then no `load`
 * event ever fires — the LQIP would sit on top of a perfectly good image for
 * the life of the page. `complete` is the only signal available after the fact.
 */
onMounted(() => {
    if (image.value?.complete === true) {
        loaded.value = true;
    }
});

/*
 * §7.8 — `alt` is always present and an empty one is a reviewed choice. The
 * three collections §9.2 lets store `NULL` are exactly the three whose §7.8
 * rule is "the entity's name is adjacent text": the employer logo, the
 * institution crest, the certification badge and the author avatar all sit
 * beside the name they would otherwise repeat, so the deterministic fallback
 * for an unrecorded alt is `''` and never a second copy of the adjacent text.
 * A caller that wants a different sentence passes it.
 */
const altText = computed(() => props.alt ?? '');
</script>

<template>
    <span
        v-if="imgVariant"
        class="relative block overflow-hidden bg-muted"
        :class="isAvatar ? 'rounded-full' : 'rounded-sm'"
        :style="ratio === null ? undefined : { aspectRatio: ratio }"
    >
        <!--
            `block` on the `<picture>`: an inline one puts the image on the text
            baseline, which lifts it off the bottom of the reserved box.
        -->
        <picture class="block">
            <source
                v-for="source in sources"
                :key="source.format"
                :type="`image/${source.format}`"
                :srcset="source.srcset"
                :sizes="sizes"
            />

            <img
                ref="image"
                :src="imgVariant.url"
                :srcset="imgSrcset === '' ? undefined : imgSrcset"
                :sizes="sizes"
                :width="imgVariant.width > 0 ? imgVariant.width : undefined"
                :height="imgVariant.height > 0 ? imgVariant.height : undefined"
                :alt="altText"
                :loading="eager ? 'eager' : 'lazy'"
                :fetchpriority="eager ? 'high' : undefined"
                decoding="async"
                class="h-full w-full object-cover"
                @load="loaded = true"
            />
        </picture>

        <!--
            The placeholder is a `background-image` on an overlay above the
            image rather than a background behind it, so the fade is the thing
            the reader actually sees: a background layer would simply be covered
            the instant the image painted.
        -->
        <span
            v-if="lqip !== null"
            aria-hidden="true"
            class="pointer-events-none absolute -inset-1 scale-105 bg-cover bg-center motion-safe:transition-opacity motion-safe:duration-500"
            :class="loaded ? 'opacity-0' : 'opacity-100'"
            :style="{
                backgroundImage: `url(${lqip})`,
                filter: 'blur(12px)',
            }"
        />
    </span>
</template>
