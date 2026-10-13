<script setup lang="ts">
import { Deferred, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import PinnedNote from '@/components/public/PinnedNote.vue';
import ResultList from '@/components/public/ResultList.vue';
import SeoHead from '@/components/public/SeoHead.vue';
import TimelineItem from '@/components/public/TimelineItem.vue';
import TagChip from '@/components/TagChip.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import type { ContentType, Tag } from '@/lib/contentTypes';

type CardItem = {
    title: string;
    summary?: string | null;
    href?: string | null;
    meta?: { label: string; value: string }[];
    tags?: Tag[];
    type?: ContentType;
    isPlaceholder?: boolean;
};

type TimelineEntry = {
    id: number;
    title: string;
    employer: string;
    employerUrl: string | null;
    location: string | null;
    startDate: string;
    endDate: string | null;
    summary: string | null;
    highlights: string[];
    tags: Tag[];
    isPlaceholder: boolean;
};

type SkillTag = Tag & { count: number };

type Props = {
    profile: {
        name: string;
        role_headline: string;
        positioning_sentence: string;
        location: string | null;
        availability: string | null;
        email: string | null;
        github_url: string | null;
        linkedin_url: string | null;
        orcid_url: string | null;
    } | null;
    atAGlance: { label: string; value: string }[];
    featuredProjects: CardItem[];
    featuredProject: CardItem | null;
    recentExperience: TimelineEntry[];
    recentWritings?: CardItem[];
    skillTags?: SkillTag[];
    education?: CardItem[];
    certifications?: CardItem[];
    publications?: CardItem[];
    awards?: CardItem[];
    title: string;
    description: string;
    canonical: string;
    jsonLd: Record<string, unknown>[];
    noindex: boolean;
};

const props = defineProps<Props>();

const siteName = usePage().props.name;

const printPage = (): void => {
    window.print();
};

const contactLinks = computed(() => {
    const profile = props.profile;

    if (!profile) {
        return [];
    }

    return [
        {
            label: 'Email',
            href: profile.email ? `mailto:${profile.email}` : null,
        },
        { label: 'GitHub', href: profile.github_url },
        { label: 'LinkedIn', href: profile.linkedin_url },
        { label: 'ORCID', href: profile.orcid_url },
    ].filter((link): link is { label: string; href: string } =>
        Boolean(link.href),
    );
});
</script>

<template>
    <SeoHead
        :title="title"
        :description="description"
        :canonical="canonical"
        :json-ld="jsonLd"
        :noindex="noindex"
    />

    <div class="space-y-16">
        <section class="space-y-8">
            <Heading
                as="h1"
                variant="display"
                :title="profile?.name ?? siteName"
                :description="profile?.role_headline"
            />

            <p
                v-if="profile?.positioning_sentence"
                class="max-w-[60ch] text-lg"
            >
                {{ profile.positioning_sentence }}
            </p>

            <div class="flex flex-wrap gap-3">
                <Button as-child size="lg">
                    <Link href="/cv">View CV</Link>
                </Button>
                <Button
                    type="button"
                    size="lg"
                    variant="outline"
                    @click="printPage"
                >
                    Print / Save as PDF
                </Button>
                <Button as-child size="lg" variant="secondary">
                    <Link href="/contact">Contact</Link>
                </Button>
            </div>

            <dl
                v-if="atAGlance.length"
                class="grid gap-4 rounded-md border-2 border-ink bg-card p-6 shadow-hard-sm sm:grid-cols-2 lg:grid-cols-4"
            >
                <div v-for="fact in atAGlance" :key="fact.label">
                    <dt
                        class="font-mono text-label tracking-widest text-muted-foreground uppercase"
                    >
                        {{ fact.label }}
                    </dt>
                    <dd class="mt-1 font-medium">{{ fact.value }}</dd>
                </div>
            </dl>

            <ul v-if="contactLinks.length" class="flex flex-wrap gap-4">
                <li v-for="link in contactLinks" :key="link.label">
                    <a
                        :href="link.href"
                        rel="noopener noreferrer"
                        class="inline-flex min-h-6 items-center underline decoration-2 underline-offset-4 hover:text-primary"
                    >
                        {{ link.label }}
                    </a>
                </li>
            </ul>

            <PinnedNote
                v-if="featuredProject"
                label="Featured project"
                :title="featuredProject.title"
                :href="featuredProject.href ?? '/projects'"
            />
        </section>

        <section class="space-y-6">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <Heading as="h2" title="Selected work" />
                <Link
                    href="/projects"
                    class="inline-flex min-h-6 items-center underline decoration-2 underline-offset-4 hover:text-primary"
                >
                    View all projects
                </Link>
            </div>

            <ResultList
                :items="featuredProjects"
                empty-title="No featured projects yet"
                empty-description="Selected work appears here once a project is published and featured."
            />
        </section>

        <section class="space-y-6">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <Heading as="h2" title="Experience" />
                <Link
                    href="/experience"
                    class="inline-flex min-h-6 items-center underline decoration-2 underline-offset-4 hover:text-primary"
                >
                    View all experience
                </Link>
            </div>

            <ul v-if="recentExperience.length" class="space-y-6">
                <TimelineItem
                    v-for="entry in recentExperience"
                    :key="entry.id"
                    :title="entry.title"
                    :employer="entry.employer"
                    :employer-url="entry.employerUrl"
                    :location="entry.location"
                    :start-date="entry.startDate"
                    :end-date="entry.endDate"
                    :summary="entry.summary"
                    :highlights="entry.highlights"
                    :tags="entry.tags"
                    :is-placeholder="entry.isPlaceholder"
                />
            </ul>
            <p v-else class="text-muted-foreground">No roles published yet.</p>
        </section>

        <section class="space-y-6">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <Heading as="h2" title="Writing highlights" />
                <Link
                    href="/writing"
                    class="inline-flex min-h-6 items-center underline decoration-2 underline-offset-4 hover:text-primary"
                >
                    View all writing
                </Link>
            </div>

            <Deferred data="recentWritings">
                <template #fallback>
                    <div aria-hidden="true" class="space-y-4">
                        <Skeleton class="h-32 w-full" />
                        <Skeleton class="h-32 w-full" />
                        <Skeleton class="h-32 w-full" />
                    </div>
                </template>

                <ResultList
                    :items="recentWritings ?? []"
                    empty-title="No writing published yet"
                    empty-description="Notes and articles appear here once they are published."
                />
            </Deferred>
        </section>

        <section class="space-y-6">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <Heading as="h2" title="Skills" />
                <Link
                    href="/tags"
                    class="inline-flex min-h-6 items-center underline decoration-2 underline-offset-4 hover:text-primary"
                >
                    Browse all tags
                </Link>
            </div>

            <Deferred data="skillTags">
                <template #fallback>
                    <div aria-hidden="true" class="flex flex-wrap gap-2">
                        <Skeleton class="h-8 w-24" />
                        <Skeleton class="h-8 w-32" />
                        <Skeleton class="h-8 w-20" />
                        <Skeleton class="h-8 w-28" />
                        <Skeleton class="h-8 w-24" />
                    </div>
                </template>

                <ul
                    v-if="(skillTags ?? []).length"
                    class="flex flex-wrap gap-2"
                >
                    <li v-for="tag in skillTags ?? []" :key="tag.slug">
                        <TagChip :tag="tag" />
                    </li>
                </ul>
                <p v-else class="text-muted-foreground">No tags yet.</p>
            </Deferred>
        </section>

        <section class="space-y-6">
            <Heading as="h2" title="Education &amp; certifications" />

            <Deferred :data="['education', 'certifications']">
                <template #fallback>
                    <div aria-hidden="true" class="grid gap-6 md:grid-cols-2">
                        <div class="space-y-4">
                            <Skeleton class="h-32 w-full" />
                            <Skeleton class="h-32 w-full" />
                        </div>
                        <div class="space-y-4">
                            <Skeleton class="h-32 w-full" />
                            <Skeleton class="h-32 w-full" />
                        </div>
                    </div>
                </template>

                <div class="grid gap-8 md:grid-cols-2">
                    <div class="space-y-4">
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-3"
                        >
                            <h3 class="font-display text-xl font-bold">
                                Education
                            </h3>
                            <Link
                                href="/education"
                                class="inline-flex min-h-6 items-center underline decoration-2 underline-offset-4 hover:text-primary"
                            >
                                View all education
                            </Link>
                        </div>
                        <ResultList
                            :items="education ?? []"
                            empty-title="No education published yet"
                        />
                    </div>

                    <div class="space-y-4">
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-3"
                        >
                            <h3 class="font-display text-xl font-bold">
                                Certifications
                            </h3>
                            <Link
                                href="/certifications"
                                class="inline-flex min-h-6 items-center underline decoration-2 underline-offset-4 hover:text-primary"
                            >
                                View all certifications
                            </Link>
                        </div>
                        <ResultList
                            :items="certifications ?? []"
                            empty-title="No certifications published yet"
                        />
                    </div>
                </div>
            </Deferred>
        </section>

        <Deferred :data="['publications', 'awards']">
            <template #fallback>
                <div aria-hidden="true" class="space-y-4">
                    <Skeleton class="h-8 w-64" />
                    <Skeleton class="h-32 w-full" />
                </div>
            </template>

            <section
                v-if="(publications ?? []).length || (awards ?? []).length"
                class="space-y-6"
            >
                <Heading as="h2" title="Publications &amp; awards" />

                <div class="grid gap-8 md:grid-cols-2">
                    <div v-if="(publications ?? []).length" class="space-y-4">
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-3"
                        >
                            <h3 class="font-display text-xl font-bold">
                                Publications
                            </h3>
                            <Link
                                href="/publications"
                                class="inline-flex min-h-6 items-center underline decoration-2 underline-offset-4 hover:text-primary"
                            >
                                View all publications
                            </Link>
                        </div>
                        <ResultList
                            :items="publications ?? []"
                            empty-title="No publications published yet"
                        />
                    </div>

                    <div v-if="(awards ?? []).length" class="space-y-4">
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-3"
                        >
                            <h3 class="font-display text-xl font-bold">
                                Awards
                            </h3>
                            <Link
                                href="/awards"
                                class="inline-flex min-h-6 items-center underline decoration-2 underline-offset-4 hover:text-primary"
                            >
                                View all awards
                            </Link>
                        </div>
                        <ResultList
                            :items="awards ?? []"
                            empty-title="No awards published yet"
                        />
                    </div>
                </div>
            </section>
        </Deferred>

        <section
            class="space-y-4 rounded-md border-2 border-ink bg-tone-marigold p-8 shadow-hard-lg"
        >
            <Heading as="h2" title="Let's talk" />
            <p class="max-w-[60ch]">
                Have a project, a role or a question in mind? Get in touch and I
                will reply by email.
            </p>
            <Button as-child size="lg">
                <Link href="/contact">Contact</Link>
            </Button>
        </section>
    </div>
</template>
