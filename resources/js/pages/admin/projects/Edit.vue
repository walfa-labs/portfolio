<script setup lang="ts">
import { Head, router, setLayoutProps } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Eye, EyeOff, Trash2 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { projects } from '@/lib/resources';
import DeleteConfirmDialog from '@/pages/admin/partials/DeleteConfirmDialog.vue';
import ResourceForm from '@/pages/admin/partials/ResourceForm.vue';
import type { AdminRecord } from '@/pages/admin/partials/resourceUrls';
import { displayName } from '@/pages/admin/partials/resourceUrls';
import { dashboard } from '@/routes/admin';
import {
    destroy as projectsDestroy,
    edit as projectsEdit,
    index as projectsIndex,
    publish as projectsPublish,
    unpublish as projectsUnpublish,
} from '@/routes/admin/projects';
import ProjectController from '@/actions/App/Http/Controllers/Admin/ProjectController';

/*
 * §5.9 — the populated project form. The page adds the two controls the form
 * itself does not carry: the publish/unpublish toggle and the delete
 * confirmation.
 */
const props = defineProps<{
    record: AdminRecord;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Projects', href: projectsIndex() },
        {
            title: displayName(props.record),
            href: projectsEdit.url(props.record),
        },
    ],
});

const pageDescription = computed(
    () =>
        `Editing ${displayName(props.record)}. The slug is derived from the title on create and never changes afterwards.`,
);

const isPublished = computed(() => props.record.status === 'published');

/*
 * Publishing answers a redirect to the index with a success toast
 * (`ProjectController::publishRecord()`), so this navigates away from the edit
 * page and there is no local state to roll back.
 */
const toggleStatus = (): void => {
    const action = isPublished.value
        ? projectsUnpublish.url(props.record)
        : projectsPublish.url(props.record);

    router.patch(action, {}, { preserveScroll: true });
};

/* Delete ----------------------------------------------------------------- */

const deleteOpen = ref(false);

const deleteTitle = computed(
    () => `Delete "${displayName(props.record)}"?`,
);

const askDelete = (): void => {
    deleteOpen.value = true;
};
</script>

<template>
    <Head title="Edit project · Admin" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Heading
            as="h1"
            title="Edit project"
            :description="pageDescription"
        />

        <!--
            Outlined, not filled: the form's submit below is the one
            `bg-primary` block this viewport is allowed (NB-3).
        -->
        <div class="flex flex-wrap items-center gap-3">
            <Button
                type="button"
                variant="outline"
                @click="toggleStatus"
            >
                <Eye v-if="!isPublished" aria-hidden="true" />
                <EyeOff v-else aria-hidden="true" />
                {{ isPublished ? 'Unpublish' : 'Publish' }}
            </Button>
        </div>

        <ResourceForm
            :descriptor="projects"
            :record="props.record"
            :action="ProjectController.update.form(props.record).action"
            method="patch"
            submit-label="Save project"
        />

        <section
            aria-labelledby="project-danger-heading"
            class="rounded-sm border-2 border-destructive p-4"
        >
            <Heading
                id="project-danger-heading"
                as="h2"
                title="Delete project"
            />
            <p class="text-meta text-muted-foreground">
                A deleted project is hidden from every list and cannot be
                restored from the admin.
            </p>
            <Button
                type="button"
                variant="outline"
                class="mt-4"
                @click="askDelete"
            >
                <Trash2 class="text-destructive" aria-hidden="true" />
                Delete project
            </Button>
        </section>

        <!--
            The dialog performs the `DELETE` itself (§5.9); this page only opens
            it and lets the server's redirect to the index take over.
        -->
        <DeleteConfirmDialog
            v-model:open="deleteOpen"
            :title="deleteTitle"
            :description="`${projects.label} records cannot be restored once they are deleted.`"
            :action="projectsDestroy.url(props.record)"
        />
    </div>
</template>
