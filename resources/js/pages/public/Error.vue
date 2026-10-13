<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

/*
 * The page renders from the `status` prop alone (§3.12).
 *
 * It deliberately reads no shared prop and mounts no `SeoHead`: maintenance mode
 * aborts inside the global middleware stack, before the `web` group starts the
 * session, so anything that depends on a session-backed shared prop can throw on
 * exactly the page whose job is to explain that the site is down. For the same
 * reason there is no analytics or ad script here, and no JSON-LD.
 */
type Copy = {
    title: string;
    heading: string;
    body: string;
    links: { label: string; href: string }[];
};

const props = defineProps<{
    status: number;
}>();

const COPY: Record<number, Copy> = {
    403: {
        title: "You don't have access to this page",
        heading: "You don't have access to this page",
        body: 'You are signed in, but this page is not yours to open. If you followed a link from somewhere else, it may be out of date.',
        links: [],
    },
    404: {
        title: 'Page not found',
        heading: 'Page not found',
        body: 'The page you asked for does not exist. It may have been renamed, or the link may be misspelled.',
        links: [
            { label: 'View the CV', href: '/cv' },
            { label: 'Read the writing', href: '/writing' },
            { label: 'Search the site', href: '/search' },
        ],
    },
    500: {
        title: 'Something went wrong',
        heading: 'Something went wrong',
        body: 'That is a fault on this end, not yours. The error has been recorded — please try again in a moment.',
        links: [{ label: 'Contact', href: '/contact' }],
    },
    503: {
        title: 'Down for maintenance',
        heading: 'Down for maintenance',
        body: 'The site is offline while it is being updated. Please try again in a few minutes.',
        links: [],
    },
};

const FALLBACK: Copy = {
    title: 'Something went wrong',
    heading: 'Something went wrong',
    body: 'That request could not be completed. Please try again, or head back to the home page.',
    links: [],
};

const copy = computed<Copy>(() => COPY[props.status] ?? FALLBACK);
</script>

<template>
    <Head :title="copy.title">
        <meta name="description" :content="copy.body" head-key="description" />
        <meta name="robots" content="noindex,nofollow" head-key="robots" />
    </Head>

    <div class="mx-auto max-w-[65ch] space-y-8">
        <p
            aria-hidden="true"
            class="font-display text-numeral leading-[0.85] font-extrabold tracking-tight text-muted-foreground tabular-nums"
        >
            {{ status }}
        </p>

        <Heading as="h1" variant="display" :title="copy.heading" />

        <p class="text-lead text-muted-foreground">{{ copy.body }}</p>

        <div class="flex flex-wrap gap-3">
            <Button as-child size="lg">
                <Link href="/">Back to the home page</Link>
            </Button>

            <Button
                v-for="link in copy.links"
                :key="link.href"
                as-child
                size="lg"
                variant="outline"
            >
                <Link :href="link.href">{{ link.label }}</Link>
            </Button>
        </div>
    </div>
</template>
