<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { projects } from '@/lib/resources';
import BulkActionBar from '@/pages/admin/partials/BulkActionBar.vue';
import DeleteConfirmDialog from '@/pages/admin/partials/DeleteConfirmDialog.vue';
import ReorderList from '@/pages/admin/partials/ReorderList.vue';
import ResourceTable from '@/pages/admin/partials/ResourceTable.vue';
import type { AdminRecord, Paginator } from '@/pages/admin/partials/resourceUrls';
import { dashboard } from '@/routes/admin';
import {
    bulkDestroy,
    bulkPublish,
    bulkUnpublish,
    create as projectsCreate,
    index as projectsIndex,
} from '@/routes/admin/projects';

/*
 * §5.9 — the project list. The filters, the "Skip to results" bypass block,
 * the table and the pagination all belong to `ResourceTable`, which owns them
 * because the `filters` prop is only meaningful to whoever renders the filter
 * controls. This page owns the selection, the bulk bar and the reorder panel.
 */
type AdminMeta = {
    type: string;
    segment: string;
    label: string;
    orderable: boolean;
    publishable: boolean;
    total: number;
    page: number;
    perPage: number;
    lastPage: number;
};

const props = defineProps<{
    records: Paginator<AdminRecord>;
    filters: Record<string, string | null>;
    meta: AdminMeta;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Projects', href: projectsIndex() },
        ],
    },
});

const selected = ref<number[]>([]);

/* Bulk actions ---------------------------------------------------------- */

const bulkPublishUrl = bulkPublish.url();
const bulkUnpublishUrl = bulkUnpublish.url();

const handleBulkAction = (value: string): void => {
    if (value === 'clear') {
        selected.value = [];

        return;
    }

    if (value === 'delete') {
        bulkDeleteOpen.value = true;

        return;
    }

    if (value === 'publish' || value === 'unpublish') {
        router.patch(
            value === 'publish' ? bulkPublishUrl : bulkUnpublishUrl,
            { ids: selected.value },
            { preserveScroll: true },
        );
    }
};

/*
 * The bulk delete reuses the single delete's confirm dialog (§5.9). The
 * dialog performs the `DELETE` itself and can only be handed a URL, so the
 * selection travels as the `ids` query string — the collection routes read
 * it through `AdminController::ids()`, which validates `$request->all()` and
 * therefore the query bag.
 */
const bulkDeleteOpen = ref(false);

const bulkDeleteUrl = computed(() =>
    bulkDestroy.url({ query: { ids: selected.value } }),
);

const bulkDeleteTitle = computed(() => {
    const count = selected.value.length;

    return `Delete ${count} ${count === 1 ? 'project' : 'projects'}?`;
});

const handleBulkConfirmed = (): void => {
    selected.value = [];
};
</script>

<template>
    <Head title="Projects · Admin" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                as="h1"
                title="Projects"
                description="Software projects you have built. Published projects with the “Featured” box ticked are the candidates for the home page “Selected work”."
            />

            <!--
                Outlined rather than filled: the pagination's current page is
                the one `bg-primary` block this viewport is allowed (NB-3), and
                it appears as soon as the list runs past one page.
            -->
            <Button as-child variant="outline">
                <Link :href="projectsCreate.url()">
                    <Plus aria-hidden="true" />
                    New project
                </Link>
            </Button>
        </div>

        <ResourceTable
            :descriptor="projects"
            :records="records"
            :filters="filters"
            v-model:selected="selected"
        />

        <BulkActionBar
            :count="selected.length"
            :actions="[
                { value: 'publish', label: 'Publish' },
                { value: 'unpublish', label: 'Unpublish' },
                { value: 'delete', label: 'Delete', destructive: true },
            ]"
            @action="handleBulkAction"
        />

        <ReorderList
            v-if="meta.orderable"
            :descriptor="projects"
            :records="records"
        />

        <DeleteConfirmDialog
            v-model:open="bulkDeleteOpen"
            :title="bulkDeleteTitle"
            :description="`${projects.label} records cannot be restored once they are deleted.`"
            :action="bulkDeleteUrl"
            @confirmed="handleBulkConfirmed"
        />
    </div>
</template>
