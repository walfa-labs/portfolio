<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { cv, home, privacy, search, sitemap } from '@/routes';
import { index as awardsIndex } from '@/routes/awards';
import { index as certificationsIndex } from '@/routes/certifications';
import { show as contactShow } from '@/routes/contact';
import { index as educationIndex } from '@/routes/education';
import { index as experienceIndex } from '@/routes/experience';
import { writings as feedWritings } from '@/routes/feeds';
import { index as projectsIndex } from '@/routes/projects';
import { index as publicationsIndex } from '@/routes/publications';
import { index as tagsIndex } from '@/routes/tags';
import { index as writingIndex } from '@/routes/writing';

/*
 * `page` is an Inertia page, `file` is one of the non-page responses (the Atom
 * feed and the XML sitemap — an Inertia visit to either would break), and
 * `external` leaves the site.
 */
type FooterLinkKind = 'page' | 'file' | 'external';

type FooterLink = {
    label: string;
    href: string;
    kind: FooterLinkKind;
};

type FooterColumn = {
    title: string;
    links: FooterLink[];
};

const repositoryUrl = 'https://github.com/walfa-labs/portfolio';

/*
 * §3.4 — three columns, every public route one click away.
 *
 * §7.2 (3.2.6) — the four consistent-help items are `Contact`, `XML sitemap`,
 * `Source repository` and `Do Not Track`, and this is their one order: Contact
 * in the `Site` column, then the three `Meta` links. Nothing may reorder them,
 * on any page, in any layout that renders this footer.
 */
const columns: FooterColumn[] = [
    {
        title: 'Content',
        links: [
            {
                label: 'Experience',
                href: experienceIndex.url(),
                kind: 'page',
            },
            { label: 'Education', href: educationIndex.url(), kind: 'page' },
            {
                label: 'Certifications',
                href: certificationsIndex.url(),
                kind: 'page',
            },
            {
                label: 'Publications',
                href: publicationsIndex.url(),
                kind: 'page',
            },
            { label: 'Projects', href: projectsIndex.url(), kind: 'page' },
            { label: 'Awards', href: awardsIndex.url(), kind: 'page' },
            { label: 'Writing', href: writingIndex.url(), kind: 'page' },
        ],
    },
    {
        title: 'Site',
        links: [
            { label: 'Home', href: home.url(), kind: 'page' },
            { label: 'CV', href: cv.url(), kind: 'page' },
            { label: 'Tags', href: tagsIndex.url(), kind: 'page' },
            { label: 'Search', href: search.url(), kind: 'page' },
            { label: 'Contact', href: contactShow.url(), kind: 'page' },
            { label: 'Atom feed', href: feedWritings.url(), kind: 'file' },
        ],
    },
    {
        title: 'Meta',
        links: [
            { label: 'XML sitemap', href: sitemap.url(), kind: 'file' },
            {
                label: 'Source repository',
                href: repositoryUrl,
                kind: 'external',
            },
            { label: 'Do Not Track', href: `${privacy.url()}#dnt`, kind: 'page' },
        ],
    },
];

/* §7.2 (2.5.8) — the footer is not the primary nav, so `min-h-6` (24 px) is the
   floor here rather than the 44 px 2.5.5 asks of the header. `min-w-6` is the
   other half of that floor: a one-word link such as "CV" is 22 px wide at the
   default font, which is under the 24×24 target. */
const footerLinkClass =
    'inline-flex min-h-6 min-w-6 items-center text-muted-foreground underline decoration-2 underline-offset-4 transition-colors hover:text-foreground hover:decoration-primary';
</script>

<template>
    <footer class="mt-16 border-t-2 border-ink bg-secondary">
        <div
            class="mx-auto grid w-full max-w-6xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-3"
        >
            <div v-for="column in columns" :key="column.title">
                <!--
                    §7.4 wants `Heading.vue` for section titles. It is not used
                    here: `Heading` wraps its title in a `<div>` and its small
                    variant adds a 2 px bottom margin, and this footer keeps the
                    `<h2>` as the `<ul>`'s immediate sibling with the column's own
                    spacing. The small variant's class string is repeated instead.
                -->
                <h2
                    class="font-mono text-label font-medium tracking-widest text-muted-foreground uppercase"
                >
                    {{ column.title }}
                </h2>

                <ul class="mt-4 space-y-1">
                    <li v-for="link in column.links" :key="link.label">
                        <Link
                            v-if="link.kind === 'page'"
                            :href="link.href"
                            :class="footerLinkClass"
                        >
                            {{ link.label }}
                        </Link>
                        <a
                            v-else
                            :href="link.href"
                            :rel="
                                link.kind === 'external'
                                    ? 'noopener noreferrer'
                                    : undefined
                            "
                            :class="footerLinkClass"
                        >
                            {{ link.label }}
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </footer>
</template>
