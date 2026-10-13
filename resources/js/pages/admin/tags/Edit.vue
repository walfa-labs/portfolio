<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import TagController from '@/actions/App/Http/Controllers/Admin/TagController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { tags } from '@/lib/resources';
import DeleteConfirmDialog from '@/pages/admin/partials/DeleteConfirmDialog.vue';
import ResourceForm from '@/pages/admin/partials/ResourceForm.vue';
import type { AdminRecord } from '@/pages/admin/partials/resourceUrls';
import { displayName } from '@/pages/admin/partials/resourceUrls';
import { dashboard } from '@/routes/admin';
import { destroy, index } from '@/routes/admin/tags';

/*
 * §5.9 — the populated form, plus the one control that is not a form field: the
 * §5.9 delete confirmation.
 *
 * There is no publish/unpublish toggle here. A tag has no `status` and no
 * `published_at` (§2.5), so the §5.8 `publish`/`unpublish` routes are
 * policy-checked no-ops and the screen never offers the control.
 *
 * `Tag::getRouteKeyName()` is `slug`, but the admin addresses a tag the way it
 * addresses the seven content types — by primary key — and `TagController`
 * accepts either, so the generated helpers are handed `record.id`.
 *
 * The delete is never a bare `DELETE`: `DeleteConfirmDialog.vue` names the
 * record, traps focus, is `Escape`-closable and performs the request against
 * `destroy(record.id)`.
 */
const props = defineProps<{
    record: AdminRecord;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Tags', href: index() },
            /* The last crumb is the current page — `Breadcrumbs.vue` renders it
               as `aria-current="page"` rather than a link, so its `href` is
               required by the type and never followed. */
            { title: 'Edit tag', href: index() },
        ],
    },
});

const descriptor = tags;

/* §5.12 — the generated `update.form()` carries the PATCH target and the verb
   the form must spoof, so neither can drift from the route. */
const updateForm = computed(() => TagController.update.form(props.record.id));

/* Delete --------------------------------------------------------------- */

const deleteOpen = ref(false);

const recordName = computed(() => displayName(props.record));
</script>

<template>
    <Head title="Edit tag · Admin">
        <!-- §5.11 layer 2: the header cannot be dropped, this cannot be
             forgotten by a client-side render. -->
        <meta head-key="robots" name="robots" content="noindex,nofollow" />
    </Head>

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Heading
            as="h1"
            title="Edit tag"
            :description="`Editing “${recordName}”.`"
        />

        <section class="space-y-3 rounded-sm border-2 border-ink bg-card p-4">
            <Heading as="h2" variant="small" title="Details" />

            <ResourceForm
                :descriptor="descriptor"
                :record="record"
                :action="updateForm.action"
                :method="updateForm.method"
                submit-label="Save changes"
            />
        </section>

        <section class="space-y-3 rounded-sm border-2 border-destructive p-4">
            <Heading as="h2" variant="small" title="Delete" />

            <p class="text-sm text-muted-foreground">
                Deleting this tag removes it from every record it is attached
                to, and cannot be undone.
            </p>

            <Button
                type="button"
                variant="destructive"
                @click="deleteOpen = true"
            >
                Delete tag
            </Button>
        </section>

        <DeleteConfirmDialog
            v-model:open="deleteOpen"
            :title="`Delete “${recordName}”?`"
            description="Tag records cannot be restored once they are deleted."
            :action="destroy(record.id).url"
        />
    </div>
</template>
