<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

const props = defineProps<{
    items: NavItem[];
    label?: string;
}>();

const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

/*
 * §6.7 — the accent lands on exactly one sidebar item. A plain prefix test lights
 * two at once, because `/admin` is a prefix of every other admin route; the
 * longest matching prefix is the section the visitor is actually in, so
 * `/admin/work-experiences/create` stays on *Work experience* while `/admin`
 * itself stays on *Dashboard*.
 */
const activeHref = computed<string | null>(() =>
    props.items
        .filter((item) => isCurrentOrParentUrl(item.href))
        .map((item) => toUrl(item.href))
        .reduce<string | null>(
            (longest, href) =>
                longest === null || href.length > longest.length
                    ? href
                    : longest,
            null,
        ),
);

/* `aria-current="page"` is the exact match only: on a create or edit screen the
   section link is highlighted but the visitor is not on it. */
const isCurrentPage = (item: NavItem): boolean => isCurrentUrl(item.href);
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel v-if="label">{{ label }}</SidebarGroupLabel>
        <nav aria-label="Main">
            <SidebarMenu>
                <SidebarMenuItem v-for="item in items" :key="item.title">
                    <!--
                        §7.1 adopts 2.5.5 (Enhanced) at 44×44 CSS px for primary
                        nav, and this `<nav aria-label="Main">` is the admin
                        shell's primary nav — the same floor `PublicHeader.vue`
                        meets with `min-h-11`. The cva's own 32 px `h-8` is a
                        height, so `min-height` wins; the collapsed rail is only
                        48 px wide with 8 px of group padding, so there the item
                        stays the square `size-9` the rail can hold.
                    -->
                    <SidebarMenuButton
                        as-child
                        class="min-h-11 group-data-[collapsible=icon]:min-h-9!"
                        :is-active="toUrl(item.href) === activeHref"
                        :tooltip="item.title"
                    >
                        <Link
                            :href="item.href"
                            :aria-current="
                                isCurrentPage(item) ? 'page' : undefined
                            "
                        >
                            <component :is="item.icon" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </nav>
    </SidebarGroup>
</template>
