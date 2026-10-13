<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import Heading from '@/components/Heading.vue';
import { dashboard } from '@/routes/admin';
import {
    bulkDestroy as certificationsBulkDestroy,
    bulkPublish as certificationsBulkPublish,
    bulkUnpublish as certificationsBulkUnpublish,
    create as certificationsCreate,
    index as certificationsIndex,
} from '@/routes/admin/certifications';
import { certifications } from '@/lib/resources';
import BulkActionBar from '@/pages/admin/partials/BulkActionBar.vue';
import DeleteConfirmDialog from '@/pages/admin/partials/DeleteConfirmDialog.vue';
import ReorderList from '@/pages/admin/partials/ReorderList.vue';
import ResourceTable from '@/pages/admin/partials/ResourceTable.vue';
import type { AdminRecord, Paginator } from '@/pages/admin/partials/resourceUrls';

/*
 * §5.9 — the certification list. The filter form, the "Skip to results"
 * bypass block, the table itself and the pagination all belong to
 * `ResourceTable`, because the `filters` prop is only meaningful to whoever
 * renders the filter controls; this page owns the selection behind the bulk
 * bar and the reorder list.
 *
 * §5.9's bulk actions: publish and unpublish are not destructive, so they
 * run straight through `router.patch` with the selected ids as the body, the
 * same way the row toggle does. Delete is destructive (§6.7), so it confirms
 * through `DeleteConfirmDialog`. The dialog performs the request itself and
 * carries no body of its own, so the ids ride in the `bulkDestroy` URL's
 * query string — the server reads ids from query and body alike.
 */
type Props = {
    records: Paginator<AdminRecord>;
    filters: {
        status: string | null;
        q: string | null;
        tag: string | null;
    };
    meta: {
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
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Certifications', href: certificationsIndex() },
        ],
    },
});

const selected = ref<number[]>([]);

const bulkActions = [
    { value: 'publish', label: 'Publish' },
    { value: 'unpublish', label: 'Unpublish' },
    { value: 'delete', label: 'Delete', destructive: true },
];

const handleBulkAction = (value: string): void => {
    if (value === 'clear') {
        selected.value = [];

        return;
    }

    if (value === 'publish' || value === 'unpublish') {
        router.patch(
            (value === 'publish'
                ? certificationsBulkPublish
                : certificationsBulkUnpublish
            ).url(),
            { ids: selected.value },
            { preserveScroll: true },
        );

        return;
    }

    if (value === 'delete') {
        deleteOpen.value = true;
    }
};

/* Bulk delete (§5.9, §6.7) ------------------------------------------- */

const deleteOpen = ref(false);

const bulkDeleteAction = computed(() =>
    certificationsBulkDestroy.url({ query: { ids: selected.value } }),
);

const deleteTitle = computed(() => {
    const count = selected.value.length;

    return count === 1
        ? 'Delete the selected certification?'
        : `Delete the ${count} selected certifications?`;
});

const deleteDescription = computed(() => {
    const count = selected.value.length;

    return count === 1
        ? 'The selected certification cannot be restored once it is deleted.'
        : `The ${count} selected certifications cannot be restored once they are deleted.`;
});

const handleConfirmed = (): void => {
    selected.value = [];
};
</script>

<template>
    <Head title="Certifications · Admin" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                as="h1"
                title="Certifications"
                description="Professional credentials. Filter by status, search, or narrow by tag — then publish, reorder or delete what you selected."
            />
            <Button as-child>
                <Link :href="certificationsCreate().url">
                    <Plus aria-hidden="true" />
                    New certification
                </Link>
            </Button>
        </div>

        <ResourceTable
            :descriptor="certifications"
            :records="props.records"
            :filters="props.filters"
            :selected="selected"
            @update:selected="selected = $event"
        />

        <BulkActionBar
            :count="selected.length"
            :actions="bulkActions"
            @action="handleBulkAction"
        />

        <ReorderList
            v-if="props.meta.orderable"
            :descriptor="certifications"
            :records="props.records"
        />
    </div>

    <DeleteConfirmDialog
        v-model:open="deleteOpen"
        :title="deleteTitle"
        :description="deleteDescription"
        :action="bulkDeleteAction"
        @confirmed="handleConfirmed"
    />
</template>
