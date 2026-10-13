<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import PlaceholderBadge from '@/components/public/PlaceholderBadge.vue';
import SeoHead from '@/components/public/SeoHead.vue';
import TimelineItem from '@/components/public/TimelineItem.vue';
import TagChip from '@/components/TagChip.vue';
import { Button } from '@/components/ui/button';
import type { ContentType, Tag } from '@/lib/contentTypes';

type CvEntry = {
    id: number;
    title: string;
    organisation: string | null;
    organisationUrl: string | null;
    location: string | null;
    startDate: string | null;
    endDate: string | null;
    date: string | null;
    summary: string | null;
    highlights: string[];
    meta: { label: string; value: string }[];
    link: { href: string; label: string } | null;
    tags: Tag[];
    type: ContentType;
    isPlaceholder: boolean;
};

type CvSection = {
    key: string;
    title: string;
    type: ContentType;
    items: CvEntry[];
};

type Props = {
    profile: {
        name: string;
        roleHeadline: string;
        location: string | null;
        availability: string | null;
        email: string | null;
        githubUrl: string | null;
        linkedinUrl: string | null;
        orcidUrl: string | null;
    } | null;
    summary: string | null;
    skills: string[];
    sections: CvSection[];
    downloadUrl: string | null;
    title: string;
    description: string;
    canonical: string;
    jsonLd: Record<string, unknown>[];
    noindex: boolean;
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: '/' },
            { title: 'CV', href: '/cv' },
        ],
    },
});

/** The one date style the page uses, mirroring `TimelineItem`'s. */
const formatMonth = (value: string): string => {
    const [year, month] = value.split('-');

    return new Date(
        Date.UTC(Number(year), Number(month) - 1, 1),
    ).toLocaleDateString('en', {
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });
};

const metaClasses =
    'mt-2 font-mono text-label tracking-widest text-muted-foreground uppercase';

/*
 * Experience renders through `TimelineItem`, whose props are a job entry's; every
 * other section is a compact row, which is what a CV reader skims. Both shapes
 * arrive from the controller in one generic entry type.
 */
const isTimeline = (section: CvSection): boolean =>
    section.key === 'experience';

const heading = computed(() =>
    props.profile?.name
        ? `Curriculum vitae — ${props.profile.name}`
        : 'Curriculum vitae',
);

const name = computed(() => props.profile?.name ?? null);
</script>

<template>
    <SeoHead
        :title="title"
        :description="description"
        :canonical="canonical"
        :json-ld="jsonLd"
        :noindex="noindex"
    />

    <article class="mx-auto max-w-[80ch] space-y-12">
        <header class="space-y-4">
            <Heading as="h1" variant="display" :title="heading" />

            <p v-if="profile?.roleHeadline" class="text-lead">
                {{ profile.roleHeadline }}
            </p>

            <p
                v-if="
                    profile?.location || profile?.availability || profile?.email
                "
                class="flex flex-wrap items-center gap-x-4 gap-y-2 text-meta text-muted-foreground"
            >
                <span v-if="profile?.location">{{ profile.location }}</span>
                <span v-if="profile?.availability">{{
                    profile.availability
                }}</span>
                <a
                    v-if="profile?.email"
                    :href="`mailto:${profile.email}`"
                    class="underline decoration-2 underline-offset-4 hover:text-primary"
                    >{{ profile.email }}</a
                >
            </p>

            <ul
                v-if="
                    profile?.githubUrl ||
                    profile?.linkedinUrl ||
                    profile?.orcidUrl
                "
                class="flex flex-wrap gap-x-4 gap-y-2 text-sm"
            >
                <li v-if="profile?.githubUrl">
                    <a
                        :href="profile.githubUrl"
                        rel="noopener noreferrer"
                        class="underline decoration-2 underline-offset-4 hover:text-primary"
                        >GitHub</a
                    >
                </li>
                <li v-if="profile?.linkedinUrl">
                    <a
                        :href="profile.linkedinUrl"
                        rel="noopener noreferrer"
                        class="underline decoration-2 underline-offset-4 hover:text-primary"
                        >LinkedIn</a
                    >
                </li>
                <li v-if="profile?.orcidUrl">
                    <a
                        :href="profile.orcidUrl"
                        rel="noopener noreferrer"
                        class="underline decoration-2 underline-offset-4 hover:text-primary"
                        >ORCID</a
                    >
                </li>
            </ul>

            <!--
                The printed page is the artefact (§3.6), so the download is a
                convenience that has no place on paper. It renders only for a
                user who owns a processed CV — the download route is scoped to
                the requesting user's own row (pinned decisions 14, 15), so a
                link without one is a 404 waiting to happen.
            -->
            <Button
                v-if="downloadUrl"
                as-child
                size="lg"
                variant="outline"
                class="print:hidden"
            >
                <a :href="downloadUrl">Download the CV (PDF)</a>
            </Button>
        </header>

        <section v-if="summary" aria-label="Summary" class="space-y-3">
            <Heading as="h2" variant="small" title="Summary" />
            <p class="max-w-[70ch] text-muted-foreground">{{ summary }}</p>
        </section>

        <section v-if="skills.length" aria-label="Skills" class="space-y-3">
            <Heading as="h2" variant="small" title="Skills" />
            <ul class="flex flex-wrap gap-2">
                <li
                    v-for="skill in skills"
                    :key="skill"
                    class="rounded-sm border-2 border-ink bg-tone-neutral px-2 py-1 font-mono text-label tracking-widest uppercase"
                >
                    {{ skill }}
                </li>
            </ul>
        </section>

        <section
            v-for="section in sections"
            :key="section.key"
            :aria-label="section.title"
            class="space-y-5"
        >
            <Heading as="h2" :title="section.title" />

            <p v-if="section.items.length === 0" class="text-muted-foreground">
                Nothing published in this section yet.
            </p>

            <ul v-else-if="isTimeline(section)" class="space-y-6">
                <TimelineItem
                    v-for="entry in section.items"
                    :key="entry.id"
                    class="print:break-inside-avoid"
                    :title="entry.title"
                    :employer="entry.organisation ?? ''"
                    :employer-url="entry.organisationUrl"
                    :location="entry.location"
                    :start-date="entry.startDate ?? ''"
                    :end-date="entry.endDate"
                    :summary="entry.summary"
                    :highlights="entry.highlights"
                    :tags="entry.tags"
                    :is-placeholder="entry.isPlaceholder"
                />
            </ul>

            <ul v-else class="space-y-4">
                <li
                    v-for="entry in section.items"
                    :key="entry.id"
                    class="print:break-inside-avoid"
                >
                    <article
                        class="rounded-md border-2 border-ink bg-card p-5 shadow-hard-sm"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <h3
                                class="font-display text-xl font-bold tracking-tight text-balance"
                            >
                                {{ entry.title }}
                            </h3>
                            <PlaceholderBadge :show="entry.isPlaceholder" />
                        </div>

                        <p
                            v-if="entry.organisation"
                            class="mt-1 text-sm font-medium"
                        >
                            <a
                                v-if="entry.organisationUrl"
                                :href="entry.organisationUrl"
                                rel="noopener noreferrer"
                                class="underline decoration-2 underline-offset-4 hover:text-primary"
                            >
                                {{ entry.organisation }}
                            </a>
                            <span v-else>{{ entry.organisation }}</span>
                        </p>

                        <p v-if="entry.startDate" :class="metaClasses">
                            <time :datetime="entry.startDate">{{
                                formatMonth(entry.startDate)
                            }}</time>
                            <span aria-hidden="true"> – </span>
                            <time
                                v-if="entry.endDate"
                                :datetime="entry.endDate"
                                >{{ formatMonth(entry.endDate) }}</time
                            >
                            <template v-else>Present</template>
                        </p>
                        <p v-else-if="entry.date" :class="metaClasses">
                            <time :datetime="entry.date">{{
                                formatMonth(entry.date)
                            }}</time>
                        </p>

                        <dl
                            v-if="entry.meta.length"
                            class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-sm text-muted-foreground"
                        >
                            <div
                                v-for="pair in entry.meta"
                                :key="pair.label"
                                class="flex gap-1.5"
                            >
                                <dt class="font-medium">{{ pair.label }}</dt>
                                <dd>{{ pair.value }}</dd>
                            </div>
                        </dl>

                        <p v-if="entry.summary" class="mt-3 text-sm">
                            {{ entry.summary }}
                        </p>

                        <p v-if="entry.link" class="mt-3 text-sm">
                            <a
                                :href="entry.link.href"
                                rel="noopener noreferrer"
                                class="underline decoration-2 underline-offset-4 hover:text-primary"
                                >{{ entry.link.label }}</a
                            >
                        </p>

                        <ul
                            v-if="entry.tags.length"
                            class="mt-4 flex flex-wrap gap-2"
                        >
                            <li v-for="tag in entry.tags" :key="tag.slug">
                                <TagChip :tag="tag" />
                            </li>
                        </ul>
                    </article>
                </li>
            </ul>
        </section>

        <footer
            class="border-t-2 border-ink pt-6 text-meta text-muted-foreground"
        >
            <p>
                <Link
                    href="/"
                    class="underline decoration-2 underline-offset-4 hover:text-primary"
                    >{{ name ?? 'Home' }}</Link
                >
                — this CV is generated from the same published content as the
                rest of the site.
            </p>
        </footer>
    </article>
</template>
