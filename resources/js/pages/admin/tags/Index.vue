<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { tags } from '@/lib/resources';
import BulkActionBar from '@/pages/admin/partials/BulkActionBar.vue';
import DeleteConfirmDialog from '@/pages/admin/partials/DeleteConfirmDialog.vue';
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
} from '@/routes/admin/tags';

/*
 * §5.8, §5.9 — `/admin/tags`, the tag list.
 *
 * `ResourceTable.vue` owns the filter form, the §7.12 "Skip to results" bypass
 * block, the `<caption>`/`<th scope>` table, the empty state and the pagination:
 * all of them read the `records` and `filters` props and nothing else, so a
 * second copy here would be a second filter form over the same query string and
 * a second bypass block pointing at the same results region. What is left for
 * this page is what only the tag list can know — its heading, its create link
 * and the selection the bulk bar reads.
 *
 * There is no `ReorderList`: `tags` is ordered by the ASCII-normalised key
 * `LOWER(name), id` and the §5.8 route set gives it no `reorder` (§2.9 item 4).
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
            { title: 'Tags', href: index() },
        ],
    },
});

const descriptor = tags;

/*
 * §5.9 — the selection belongs to the page, so the table and the bulk bar can
 * share it without either owning the other's state.
 */
const selected = ref<number[]>([]);

/*
 * §5.9's three bulk actions. A tag has no publication state (§2.5), so the
 * server sends `meta.publishable: false` and the bar carries the delete alone;
 * the guard is what keeps publish and unpublish off this list.
 */
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
 * selection before the visit starts, and the dialog has already been handed the
 * URL by then.
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
        ? 'Delete 1 tag?'
        : `Delete ${deleteIds.value.length} tags?`,
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
    <Head title="Tags · Admin">
        <!-- §5.11 layer 2: the header cannot be dropped, this cannot be
             forgotten by a client-side render. -->
        <meta head-key="robots" name="robots" content="noindex,nofollow" />
    </Head>

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Heading
            as="h1"
            title="Tags"
            description="Every tag, in the ASCII-normalised order the public site uses. Attaching a tag happens on the record's own form."
        />

        <div class="flex flex-wrap items-center gap-3">
            <Button as-child>
                <Link :href="create()">New tag</Link>
            </Button>
        </div>

        <ResourceTable
            v-model:selected="selected"
            :descriptor="descriptor"
            :records="records"
            :filters="filters"
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
            description="Tag records cannot be restored once they are deleted."
            :action="deleteAction"
            @confirmed="handleConfirmed"
        />
    </div>
</template>
