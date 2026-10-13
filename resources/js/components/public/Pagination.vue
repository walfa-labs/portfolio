<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

/**
 * One entry of Laravel's `LengthAwarePaginator::linkCollection()`. The list always
 * begins with Previous and ends with Next; a truncated window carries an entry
 * whose `url` is null and whose label is the ellipsis.
 */
type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
    page?: number | null;
};

type PaginationMeta = {
    current_page?: number;
    last_page?: number;
    per_page?: number;
    total?: number;
};

type Control =
    | { kind: 'previous'; url: string | null; text: string }
    | { kind: 'next'; url: string | null; text: string }
    | { kind: 'page'; url: string; text: string; active: boolean }
    | { kind: 'ellipsis' };

const props = defineProps<{
    links: PaginationLink[];
    meta?: object;
}>();

const meta = computed(() => props.meta as PaginationMeta | undefined);

/*
 * Previous/Next labels come from `__('pagination.previous')` and
 * `__('pagination.next')`, whose default values are `&laquo; Previous` and
 * `Next &raquo;`. They are HTML entities, and Vue would print them literally, so
 * they are decoded here and the decorative characters are trimmed so the visible
 * text is the word itself.
 */
const ENTITIES: Record<string, string> = {
    '&amp;': '&',
    '&hellip;': '…',
    '&laquo;': '«',
    '&lsaquo;': '‹',
    '&nbsp;': ' ',
    '&raquo;': '»',
    '&rsaquo;': '›',
};

const fromCodePoint = (code: number): string =>
    Number.isFinite(code) && code >= 0 && code <= 0x10ffff
        ? String.fromCodePoint(code)
        : '';

const decode = (label: string): string =>
    label
        .replace(/&#x([0-9a-f]+);/gi, (_, hex: string) =>
            fromCodePoint(Number.parseInt(hex, 16)),
        )
        .replace(/&#(\d+);/g, (_, code: string) => fromCodePoint(Number(code)))
        .replace(
            /&[a-z]+;/gi,
            (entity) => ENTITIES[entity.toLowerCase()] ?? entity,
        );

const isEllipsis = (label: string): boolean =>
    /^[.…]+$/.test(decode(label).trim());

/** `&laquo; Previous` → `Previous`; a label that is decoration alone keeps its raw text. */
const plainText = (label: string): string => {
    const decoded = decode(label);
    const trimmed = decoded
        .replace(/^[^\p{L}\p{N}]+/u, '')
        .replace(/[^\p{L}\p{N}]+$/u, '')
        .trim();

    return trimmed === '' ? decoded.trim() : trimmed;
};

const controls = computed<Control[]>(() => {
    const last = props.links.length - 1;

    return props.links.flatMap((link, index): Control[] => {
        if (index === 0) {
            return [
                {
                    kind: 'previous',
                    url: link.url,
                    text: plainText(link.label) || 'Previous',
                },
            ];
        }

        if (index === last) {
            return [
                {
                    kind: 'next',
                    url: link.url,
                    text: plainText(link.label) || 'Next',
                },
            ];
        }

        if (isEllipsis(link.label)) {
            return [{ kind: 'ellipsis' }];
        }

        if (link.url === null) {
            return [];
        }

        return [
            {
                kind: 'page',
                url: link.url,
                text: decode(link.label).trim(),
                active: link.active,
            },
        ];
    });
});

/** A single page is not pagination; the nav would be two dead controls. */
const paginated = computed(
    () =>
        controls.value.filter((control) => control.kind === 'page').length > 1,
);

const summary = computed(() => {
    const current = meta.value?.current_page;
    const total = meta.value?.last_page;

    return typeof current === 'number' && typeof total === 'number'
        ? `Page ${current} of ${total}`
        : '';
});

/*
 * §7.1 adopts 2.5.5 (AAA) for pagination, so every control is at least 44×44 CSS
 * px — above the 24×24 floor §7.2 sets for the rest of the site. The controls are
 * `ui/button`s, which is what carries the §6.3 press-lift and, through
 * `[data-slot='button']`, the reduced-motion rule that drops the translation while
 * keeping the shadow step.
 */
const CONTROL_SIZE = 'min-h-11 min-w-11 px-3';

/* The current page is not a link — the §7.6 rule the breadcrumb trail also follows
   — so it carries the button box without the lift, and states itself with
   `aria-current`, the border and the bold weight rather than colour alone (§7.10). */
const CURRENT =
    'inline-flex min-h-11 min-w-11 items-center justify-center rounded-sm border-2 border-ink bg-primary px-3 text-sm font-bold text-primary-foreground';

const ELLIPSIS =
    'inline-flex min-h-11 min-w-11 items-center justify-center text-muted-foreground';
</script>

<template>
    <nav v-if="paginated" aria-label="Pagination" class="space-y-3">
        <p v-if="summary" class="sr-only">{{ summary }}</p>

        <ul class="flex flex-wrap items-center gap-2">
            <li v-for="(control, index) in controls" :key="index">
                <template v-if="control.kind === 'previous'">
                    <Button
                        v-if="control.url"
                        as-child
                        variant="outline"
                        :class="CONTROL_SIZE"
                    >
                        <Link
                            :href="control.url"
                            :aria-label="`${control.text} page`"
                        >
                            <ChevronLeft aria-hidden="true" />
                            <span>{{ control.text }}</span>
                        </Link>
                    </Button>
                    <Button
                        v-else
                        variant="outline"
                        disabled
                        :class="CONTROL_SIZE"
                    >
                        <ChevronLeft aria-hidden="true" />
                        <span>{{ control.text }}</span>
                    </Button>
                </template>

                <template v-else-if="control.kind === 'next'">
                    <Button
                        v-if="control.url"
                        as-child
                        variant="outline"
                        :class="CONTROL_SIZE"
                    >
                        <Link
                            :href="control.url"
                            :aria-label="`${control.text} page`"
                        >
                            <span>{{ control.text }}</span>
                            <ChevronRight aria-hidden="true" />
                        </Link>
                    </Button>
                    <Button
                        v-else
                        variant="outline"
                        disabled
                        :class="CONTROL_SIZE"
                    >
                        <span>{{ control.text }}</span>
                        <ChevronRight aria-hidden="true" />
                    </Button>
                </template>

                <span
                    v-else-if="control.kind === 'ellipsis'"
                    :class="ELLIPSIS"
                    aria-hidden="true"
                    >…</span
                >

                <span
                    v-else-if="control.active"
                    :class="CURRENT"
                    aria-current="page"
                    >{{ control.text }}</span
                >

                <Button v-else as-child variant="outline" :class="CONTROL_SIZE">
                    <Link
                        :href="control.url"
                        :aria-label="`Page ${control.text}`"
                        >{{ control.text }}</Link
                    >
                </Button>
            </li>
        </ul>
    </nav>
</template>
