<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <!--
        §3.9 — descriptions must be non-empty on every route. The auth pages
        already compute one and pass it down as a layout prop; without this
        tag the auth surface is the only one that ships no meta description.
        `v-if` keeps an empty tag from ever being emitted, and `head-key`
        lets Inertia de-duplicate the tag against `SeoHead`'s when a visit
        crosses the auth/public boundary.
    -->
    <Head>
        <meta
            v-if="description"
            head-key="description"
            name="description"
            :content="description"
        />
    </Head>

    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
    >
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center gap-2 font-medium"
                    >
                        <div
                            class="mb-1 flex h-9 w-9 items-center justify-center rounded-md"
                        >
                            <AppLogoIcon
                                class="size-9 fill-current text-[var(--foreground)] dark:text-white"
                            />
                        </div>
                        <span class="sr-only">{{ title }}</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-medium">{{ title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>

        <!--
            §7.2 (3.2.6) — the consistent-help mechanism. §7.3 forbids a `<footer>`
            landmark on `AuthLayout`, so the Contact link lives inside `<main>`
            instead of a second landmark.
        -->
        <Link
            href="/contact"
            class="inline-flex min-h-6 items-center text-sm text-foreground underline decoration-primary/40 decoration-2 underline-offset-4 transition-colors hover:decoration-primary"
        >
            Contact
        </Link>
    </div>
</template>
