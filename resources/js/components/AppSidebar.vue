<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Award,
    BadgeCheck,
    BookOpen,
    Briefcase,
    FolderGit2,
    GraduationCap,
    Inbox,
    LayoutGrid,
    NotebookPen,
    Tags,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes/admin';
import { index as awardsIndex } from '@/routes/admin/awards';
import { index as certificationsIndex } from '@/routes/admin/certifications';
import { index as educationIndex } from '@/routes/admin/educations';
import { index as messagesIndex } from '@/routes/admin/messages';
import { index as projectsIndex } from '@/routes/admin/projects';
import { index as publicationsIndex } from '@/routes/admin/publications';
import { index as tagsIndex } from '@/routes/admin/tags';
import { index as workExperiencesIndex } from '@/routes/admin/work-experiences';
import { index as writingsIndex } from '@/routes/admin/writings';
import type { NavItem } from '@/types';

/*
 * §5.8 — the admin navigation, in the one order §5.8 lists: the dashboard, the
 * eight content types, then the contact inbox. Nothing here points at the public
 * site: the consistent-help links live in the shared footer `AppSidebarLayout`
 * renders, so no admin item duplicates them (§7.2, 3.2.6).
 *
 * The starter kit's Repository/Documentation links are gone for that same
 * reason — the footer's `Meta` column already carries "Source repository".
 */
const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Work experience',
        href: workExperiencesIndex(),
        icon: Briefcase,
    },
    {
        title: 'Education',
        href: educationIndex(),
        icon: GraduationCap,
    },
    {
        title: 'Certifications',
        href: certificationsIndex(),
        icon: BadgeCheck,
    },
    {
        title: 'Publications',
        href: publicationsIndex(),
        icon: BookOpen,
    },
    {
        title: 'Projects',
        href: projectsIndex(),
        icon: FolderGit2,
    },
    {
        title: 'Awards',
        href: awardsIndex(),
        icon: Award,
    },
    {
        title: 'Writings',
        href: writingsIndex(),
        icon: NotebookPen,
    },
    {
        title: 'Tags',
        href: tagsIndex(),
        icon: Tags,
    },
    {
        title: 'Messages',
        href: messagesIndex(),
        icon: Inbox,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" label="Admin" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
