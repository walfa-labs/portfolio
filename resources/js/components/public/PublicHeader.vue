<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, Menu } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import { cv, home, search } from '@/routes';
import { index as awardsIndex } from '@/routes/awards';
import { index as certificationsIndex } from '@/routes/certifications';
import { show as contactShow } from '@/routes/contact';
import { index as educationIndex } from '@/routes/education';
import { index as experienceIndex } from '@/routes/experience';
import { index as projectsIndex } from '@/routes/projects';
import { index as publicationsIndex } from '@/routes/publications';
import { index as tagsIndex } from '@/routes/tags';
import { index as writingIndex } from '@/routes/writing';

type NavLink = {
    label: string;
    href: string;
};

/*
 * §3.4 — six primary links in order, then a `More` dropdown holding the other
 * five. The sheet lists the same eleven flat, because a dropdown inside a
 * mobile sheet is one interaction too many.
 */
const primaryNav: NavLink[] = [
    { label: 'CV', href: cv.url() },
    { label: 'Experience', href: experienceIndex.url() },
    { label: 'Projects', href: projectsIndex.url() },
    { label: 'Writing', href: writingIndex.url() },
    { label: 'Publications', href: publicationsIndex.url() },
    { label: 'Contact', href: contactShow.url() },
];

const overflowNav: NavLink[] = [
    { label: 'Education', href: educationIndex.url() },
    { label: 'Certifications', href: certificationsIndex.url() },
    { label: 'Awards', href: awardsIndex.url() },
    { label: 'Tags', href: tagsIndex.url() },
    { label: 'Search', href: search.url() },
];

const mobileNav: NavLink[] = [...primaryNav, ...overflowNav];

const page = usePage();
const siteName = page.props.name;

const menuOpen = ref(false);

const currentPath = computed(() => page.url.split('?')[0]);

/* §3.4 — `/writing/some-slug` still highlights `Writing`, so a section match is a
   prefix match; `/` is the only link that must not swallow the whole site. */
const isActive = (href: string): boolean =>
    href === '/'
        ? currentPath.value === '/'
        : currentPath.value === href ||
          currentPath.value.startsWith(`${href}/`);

const overflowIsActive = computed(() =>
    overflowNav.some((item) => isActive(item.href)),
);

/* §7.2 — primary nav is the 44×44 (2.5.5) surface, so every link here is at
   least `min-h-11`; the `border-transparent` resting border keeps the box from
   shifting when the ink border appears. */
const navLinkClass = (href: string): string =>
    cn(
        'inline-flex min-h-11 items-center rounded-sm border-2 px-3 text-sm font-medium transition',
        isActive(href)
            ? 'border-ink bg-secondary font-semibold'
            : 'border-transparent hover:border-ink hover:bg-secondary',
    );

const sheetLinkClass = (href: string): string =>
    cn(
        'flex min-h-11 w-full items-center rounded-sm border-2 px-3 text-sm font-medium transition',
        isActive(href)
            ? 'border-ink bg-secondary font-semibold'
            : 'border-transparent hover:border-ink hover:bg-secondary',
    );
</script>

<template>
    <header class="sticky top-0 z-sticky border-b-2 border-ink bg-background">
        <div
            class="mx-auto flex h-16 w-full max-w-6xl items-center justify-between gap-4 px-4 sm:px-6"
        >
            <Link
                :href="home()"
                class="flex min-h-11 items-center gap-2 font-display text-lg font-bold tracking-tight"
            >
                <AppLogoIcon class="size-8 text-ink" />
                <span class="truncate">{{ siteName }}</span>
            </Link>

            <!--
                §8.4 item 4 — the site navigation is public, non-personal content
                and is unmasked so heatmaps show which sections people reach for.
                The `<nav>` is unmasked, not the links inside it.
            -->
            <nav
                aria-label="Main"
                data-clarity-unmask="true"
                class="hidden lg:block"
            >
                <ul class="flex items-center gap-1">
                    <li v-for="item in primaryNav" :key="item.href">
                        <Link
                            :href="item.href"
                            :class="navLinkClass(item.href)"
                            :aria-current="
                                isActive(item.href) ? 'page' : undefined
                            "
                        >
                            {{ item.label }}
                        </Link>
                    </li>
                    <li>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    class="min-h-11 gap-1 px-3"
                                    :class="
                                        overflowIsActive
                                            ? 'border-ink bg-secondary font-semibold'
                                            : ''
                                    "
                                >
                                    More
                                    <ChevronDown
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="min-w-44">
                                <DropdownMenuItem
                                    v-for="item in overflowNav"
                                    :key="item.href"
                                    as-child
                                >
                                    <Link
                                        :href="item.href"
                                        class="flex min-h-11 w-full cursor-pointer items-center"
                                        :aria-current="
                                            isActive(item.href)
                                                ? 'page'
                                                : undefined
                                        "
                                    >
                                        {{ item.label }}
                                    </Link>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </li>
                </ul>
            </nav>

            <Sheet v-model:open="menuOpen">
                <SheetTrigger as-child>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        class="lg:hidden"
                    >
                        <Menu class="size-5" aria-hidden="true" />
                        <span class="sr-only">Open main menu</span>
                    </Button>
                </SheetTrigger>
                <SheetContent side="right">
                    <SheetHeader>
                        <SheetTitle>Menu</SheetTitle>
                    </SheetHeader>
                    <nav
                        aria-label="Main"
                        data-clarity-unmask="true"
                        class="px-4 pb-6"
                    >
                        <ul class="flex flex-col gap-1">
                            <li v-for="item in mobileNav" :key="item.href">
                                <Link
                                    :href="item.href"
                                    :class="sheetLinkClass(item.href)"
                                    :aria-current="
                                        isActive(item.href) ? 'page' : undefined
                                    "
                                    @click="menuOpen = false"
                                >
                                    {{ item.label }}
                                </Link>
                            </li>
                        </ul>
                    </nav>
                </SheetContent>
            </Sheet>
        </div>
    </header>
</template>
