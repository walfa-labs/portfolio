<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { projects } from '@/lib/resources';
import ResourceForm from '@/pages/admin/partials/ResourceForm.vue';
import { dashboard } from '@/routes/admin';
import {
    create as projectsCreate,
    index as projectsIndex,
} from '@/routes/admin/projects';
import ProjectController from '@/actions/App/Http/Controllers/Admin/ProjectController';

/*
 * §5.9 — the blank project form. `record` is `null` because there is nothing
 * to edit, and `lastCreated` is the previous save of the same type, read
 * server-side so it survives Inertia navigation. The form offers it through
 * its "Prefill from last entry" button and prefills nothing until the admin
 * activates it.
 */
const props = defineProps<{
    record: null;
    lastCreated: Record<string, unknown> | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Projects', href: projectsIndex() },
            { title: 'New project', href: projectsCreate.url() },
        ],
    },
});
</script>

<template>
    <Head title="New project · Admin" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Heading
            as="h1"
            title="New project"
            description="Create a project. The slug is derived from the title and never changes afterwards."
        />

        <ResourceForm
            :descriptor="projects"
            :record="props.record"
            :action="ProjectController.store.form().action"
            method="post"
            submit-label="Create project"
            :last-created="props.lastCreated"
        />
    </div>
</template>
