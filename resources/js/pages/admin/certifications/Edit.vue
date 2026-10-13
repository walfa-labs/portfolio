<script setup lang="ts">
import { Head, router, setLayoutProps } from '@inertiajs/vue3';
import { Eye, EyeOff, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import CertificationController from '@/actions/App/Http/Controllers/Admin/CertificationController';
import { Button } from '@/components/ui/button';
import Heading from '@/components/Heading.vue';
import { dashboard } from '@/routes/admin';
import {
    destroy as certificationDestroy,
    edit as certificationEdit,
    index as certificationsIndex,
    publish as certificationPublish,
    unpublish as certificationUnpublish,
} from '@/routes/admin/certifications';
import { certifications } from '@/lib/resources';
import DeleteConfirmDialog from '@/pages/admin/partials/DeleteConfirmDialog.vue';
import ResourceForm from '@/pages/admin/partials/ResourceForm.vue';
import type { AdminRecord } from '@/pages/admin/partials/resourceUrls';
import { displayName } from '@/pages/admin/partials/resourceUrls';

/*
 * §5.9 — the populated certification form. The publish / unpublish control is
 * the row-action pair repeated on the record itself: it patches the single
 * record's state without a round-trip through the form, and the server
 * answers with the index redirect and a toast. Delete is never a bare
 * `DELETE` — it confirms through `DeleteConfirmDialog` first.
 */
const props = defineProps<{
    record: AdminRecord;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Certifications', href: certificationsIndex() },
        { title: 'Edit', href: certificationEdit(props.record) },
    ],
});

/* A content record carries a status; the control is the row toggle's twin. */
const isPublishable = computed(
    () => typeof props.record.status === 'string',
);

const togglePublish = (): void => {
    router.patch(
        (props.record.status === 'published'
            ? certificationUnpublish
            : certificationPublish
        ).url(props.record),
        {},
        { preserveScroll: true },
    );
};

/* Delete (§5.9) ------------------------------------------------------- */

const deleteOpen = ref(false);

const destroyUrl = computed(() =>
    certificationDestroy.url(props.record),
);

const deleteTitle = computed(() => {
    const name = displayName(props.record);

    return name.startsWith('#')
        ? 'Delete this certification?'
        : `Delete "${name}"?`;
});

const askDelete = (): void => {
    deleteOpen.value = true;
};
</script>

<template>
    <Head title="Edit certification · Admin" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Heading
            as="h1"
            title="Edit certification"
            description="Update the credential, its issuer, its dates and its publication state."
        />

        <ResourceForm
            :descriptor="certifications"
            :record="props.record"
            :action="CertificationController.update.form(props.record).action"
            method="post"
            submit-label="Save changes"
        />

        <div class="flex flex-wrap items-center gap-4">
            <Button
                v-if="isPublishable"
                type="button"
                variant="ghost"
                size="icon-sm"
                @click="togglePublish"
            >
                <Eye v-if="props.record.status !== 'published'" aria-hidden="true" />
                <EyeOff v-else aria-hidden="true" />
                <span class="sr-only">
                    {{
                        props.record.status === 'published'
                            ? `Unpublish ${displayName(props.record)}`
                            : `Publish ${displayName(props.record)}`
                    }}
                </span>
            </Button>

            <Button type="button" variant="outline" @click="askDelete">
                <Trash2 class="text-destructive" aria-hidden="true" />
                Delete
            </Button>
        </div>
    </div>

    <DeleteConfirmDialog
        v-model:open="deleteOpen"
        :title="deleteTitle"
        description="Certification records cannot be restored once they are deleted."
        :action="destroyUrl"
    />
</template>
