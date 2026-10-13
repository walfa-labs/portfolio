<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { publications } from '@/lib/resources';
import BulkActionBar from '@/pages/admin/partials/BulkActionBar.vue';
import DeleteConfirmDialog from '@/pages/admin/partials/DeleteConfirmDialog.vue';
import ReorderList from '@/pages/admin/partials/ReorderList.vue';
import ResourceTable from '@/pages/admin/partials/ResourceTable.vue';
import type {
    AdminRecord,
    Paginator,
} from '@/pages/admin/partials/resourceUrls';
import { dashboard } from '@/routes/admin';
import {
    bulkDestroy,
    bulkPublish,
    bulkUnpublish,
    create,
    index,
} from '@/routes/admin/publications';

/*
 * §5.8, §5.9 — `/admin/publications`, the papers list.
 *
 * `ResourceTable.vue` owns the filter form, the §7.12 "Skip to results" bypass
 * block, the `<caption>`/`<th scope>` table and the pagination: all four are
 * driven by the `records` and `filters` props and by nothing else, so a second
 * copy here would be a second filter form over the same query string. What is
 * left for this page is what only the resource's own page can know — its
 * heading, its create link, the selection the bulk bar reads, and the reorder
 * control §5.9 gives the six ordered types.
 */
type Meta = {
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
    meta: Meta;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Publications', href: index() },
        ],
    },
});

const descriptor = publications;

/*
 * §5.9 — the selection belongs to the page, so the table and the bulk bar can
 * share it without either owning the other's state.
 */
const selected = ref<number[]>([]);

/* §5.9's three bulk actions. `meta.publishable` is what keeps publish and
   unpublish off a resource with no publication state (`tags`). */
const bulkActions = computed<
    { value: string; label: string; destructive?: boolean }[]
>(() => [
    ...(props.meta.publishable
        ? [
              { value: 'publish', label: 'Publish selected' },
              { value: 'unpublish', label: 'Unpublish selected' },
          ]
        : []),
    { value: 'delete', label: 'Delete selected', destructive: true },
]);

/* Bulk delete ---------------------------------------------------------- */

const deleteOpen = ref(false);

/*
 * The ids are held apart from the live selection: `confirmed` clears the
 * selection before the visit starts, and the dialog has already been handed
 * the URL by then.
 */
const deleteIds = ref<number[]>([]);

/*
 * §5.8's bulk delete takes `ids: int[]` in the request body, and
 * `DeleteConfirmDialog.vue` performs the request itself from the single `action`
 * URL it is given. The ids therefore ride in the query string, which Laravel
 * reads through `$request->all()` exactly as it reads a body — the same
 * query-string `_method` the generated `update.form()` relies on.
 */
const deleteAction = computed(() =>
    bulkDestroy.url({ query: { ids: deleteIds.value } }),
);

const deleteTitle = computed(() =>
    deleteIds.value.length === 1
        ? 'Delete 1 publication record?'
        : `Delete ${deleteIds.value.length} publication records?`,
);

const handleBulkAction = (value: string): void => {
    if (value === 'clear') {
        selected.value = [];
        return;
    }

    if (value === 'delete') {
        deleteIds.value = [...selected.value];
        deleteOpen.value = true;
        return;
    }

    router.patch(
        value === 'publish' ? bulkPublish.url() : bulkUnpublish.url(),
        { ids: selected.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = [];
            },
        },
    );
};

const handleConfirmed = (): void => {
    selected.value = [];
};
</script>

<template>
    <Head title="Publications · Admin">
        <!-- §5.11 layer 2: the header cannot be dropped, this cannot be
             forgotten by a client-side render. -->
        <meta head-key="robots" name="robots" content="noindex,nofollow" />
    </Head>

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Heading
            as="h1"
            title="Publications"
            description="Every paper, draft or published, in the order the public publications page renders them."
        />

        <div class="flex flex-wrap items-center gap-3">
            <Button as-child>
                <Link :href="create()">New publication</Link>
            </Button>
        </div>

        <ResourceTable
            v-model:selected="selected"
            :descriptor="descriptor"
            :records="records"
            :filters="filters"
        />

        <ReorderList
            v-if="meta.orderable"
            :descriptor="descriptor"
            :records="records"
        />

        <BulkActionBar
            v-if="selected.length > 0"
            :count="selected.length"
            :actions="bulkActions"
            @action="handleBulkAction"
        />

        <DeleteConfirmDialog
            v-model:open="deleteOpen"
            :title="deleteTitle"
            description="Publication records cannot be restored once they are deleted."
            :action="deleteAction"
            @confirmed="handleConfirmed"
        />
    </div>
</template>
