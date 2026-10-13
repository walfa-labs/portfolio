<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AlertError from '@/components/AlertError.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/public/Pagination.vue';
import ResultList from '@/components/public/ResultList.vue';
import SeoHead from '@/components/public/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    contentTypeLabels,
    type ContentType,
    type Tag,
} from '@/lib/contentTypes';
import { search as searchRoute } from '@/routes';

type CardItem = {
    id: number;
    title: string;
    summary?: string | null;
    href: string;
    meta?: { label: string; value: string }[];
    tags?: Tag[];
    type?: ContentType;
    isPlaceholder?: boolean;
};

type Props = {
    q: string;
    types: ContentType[];
    results: CardItem[] | null;
    links: { url: string | null; label: string; active: boolean }[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    } | null;
    message: string | null;
    title: string;
    description: string;
    canonical: string;
    jsonLd: Record<string, unknown>[];
    noindex: boolean;
    robots?: string | null;
};

const props = defineProps<Props>();

/* §3.4 — every page except `/` carries a trail, including the search screen. */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: '/' },
            { title: 'Search', href: '/search' },
        ],
    },
});

const TYPE_ORDER: ContentType[] = [
    'work_experience',
    'education',
    'certification',
    'publication',
    'project',
    'award',
    'writing',
];

const query = ref(props.q);
const type = ref<ContentType | ''>(
    props.types.length === 1 ? props.types[0] : '',
);

/*
 * Progressive enhancement: without JavaScript the native `GET` submission
 * builds the same URL, and with it the visit stays inside Inertia (§3.8).
 */
const submit = (): void => {
    router.get(
        searchRoute().url,
        type.value === ''
            ? { q: query.value }
            : { q: query.value, type: type.value },
    );
};
</script>

<template>
    <SeoHead
        :title="title"
        :description="description"
        :canonical="canonical"
        :json-ld="jsonLd"
        :noindex="noindex"
        :robots="robots"
    />

    <div class="space-y-8">
        <Heading as="h1" title="Search" :description="description" />

        <form
            method="get"
            :action="searchRoute().url"
            class="space-y-4 rounded-md border-2 border-ink bg-card p-6 shadow-hard-sm"
            @submit.prevent="submit"
        >
            <div class="space-y-2">
                <Label for="search-q">Search this site</Label>
                <Input
                    id="search-q"
                    v-model="query"
                    type="search"
                    name="q"
                    minlength="2"
                    maxlength="100"
                    aria-describedby="search-q-hint"
                    class="md:max-w-md"
                />
                <p id="search-q-hint" class="text-meta text-muted-foreground">
                    Enter between 2 and 100 characters. Results are ranked by
                    tag, then title, then description.
                </p>
            </div>

            <div class="space-y-2">
                <Label for="search-type">Content type</Label>
                <select
                    id="search-type"
                    v-model="type"
                    name="type"
                    class="h-9 w-full rounded-sm border-2 border-ink bg-card px-3 text-base outline-hidden focus-visible:border-ring md:max-w-xs md:text-sm"
                >
                    <option value="">All content types</option>
                    <option
                        v-for="value in TYPE_ORDER"
                        :key="value"
                        :value="value"
                    >
                        {{ contentTypeLabels[value] }}
                    </option>
                </select>
            </div>

            <Button type="submit" size="lg">Search</Button>
        </form>

        <AlertError
            v-if="message"
            :errors="[message]"
            title="That search was not run."
        />

        <template v-if="results !== null">
            <ResultList
                :items="results"
                :empty-title="`No results for “${q}”`"
                empty-description="Try a shorter word, a technology name, or browse the tags."
            />

            <Pagination
                v-if="results.length"
                :links="links"
                :meta="meta ?? undefined"
            />
        </template>

        <EmptyState
            v-else-if="!message"
            title="Search the site"
            description="Every published experience entry, project, publication, award and article is searchable here."
        />
    </div>
</template>
