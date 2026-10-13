<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Eye, EyeOff } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import PublicationController from '@/actions/App/Http/Controllers/Admin/PublicationController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { publications } from '@/lib/resources';
import DeleteConfirmDialog from '@/pages/admin/partials/DeleteConfirmDialog.vue';
import ResourceForm from '@/pages/admin/partials/ResourceForm.vue';
import type { AdminRecord } from '@/pages/admin/partials/resourceUrls';
import { displayName } from '@/pages/admin/partials/resourceUrls';
import { dashboard } from '@/routes/admin';
import {
    destroy,
    index,
    publish,
    unpublish,
} from '@/routes/admin/publications';

/*
 * §5.9 — the populated form, plus the two controls that are not form fields:
 * the §5.9 publish/unpublish toggle and the §5.9 delete confirmation.
 *
 * The delete is never a bare `DELETE`: `DeleteConfirmDialog.vue` names the
 * record, traps focus, is `Escape`-closable and performs the request against
 * `destroy(record)`.
 */
const props = defineProps<{
    record: AdminRecord;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Publications', href: index() },
            /* The last crumb is the current page — `Breadcrumbs.vue` renders it
               as `aria-current="page"` rather than a link, so its `href` is
               required by the type and never followed. */
            { title: 'Edit publication', href: index() },
        ],
    },
});

const descriptor = publications;

/* §5.12 — the generated `update.form()` carries the PATCH target and the verb
   the form must spoof, so neither can drift from the route. */
const updateForm = computed(() =>
    PublicationController.update.form(props.record),
);

/* Publish / unpublish -------------------------------------------------- */

/*
 * §5.10's one optimistic case on this screen: the badge flips before the visit
 * and rolls back if the request fails, exactly as the list's own row toggle
 * does. Fresh props are the authority once they arrive.
 */
const pendingStatus = ref<string | null>(null);

const status = computed(
    () => pendingStatus.value ?? props.record.status ?? 'draft',
);

const isPublished = computed(() => status.value === 'published');

const publishing = ref(false);

watch(
    () => props.record.status,
    () => {
        pendingStatus.value = null;
    },
);

const togglePublication = (): void => {
    const next = isPublished.value ? 'draft' : 'published';
    const previous = pendingStatus.value;

    pendingStatus.value = next;
    publishing.value = true;

    router.patch(
        next === 'published'
            ? publish(props.record).url
            : unpublish(props.record).url,
        {},
        {
            preserveScroll: true,
            onError: () => {
                pendingStatus.value = previous;
            },
            onFinish: () => {
                publishing.value = false;
            },
        },
    );
};

/* Delete --------------------------------------------------------------- */

const deleteOpen = ref(false);

const recordName = computed(() => displayName(props.record));
</script>

<template>
    <Head title="Edit publication · Admin">
        <!-- §5.11 layer 2: the header cannot be dropped, this cannot be
             forgotten by a client-side render. -->
        <meta head-key="robots" name="robots" content="noindex,nofollow" />
    </Head>

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Heading
            as="h1"
            title="Edit publication"
            :description="`Editing “${recordName}”.`"
        />

        <section class="space-y-3 rounded-sm border-2 border-ink bg-card p-4">
            <Heading as="h2" variant="small" title="Publication" />

            <div class="flex flex-wrap items-center gap-3">
                <Badge :variant="isPublished ? 'default' : 'outline'">
                    {{ isPublished ? 'Published' : 'Draft' }}
                </Badge>

                <p class="text-meta text-muted-foreground">
                    Publishing sets the status and stamps the publication date.
                    The form below can set both by hand instead.
                </p>

                <Button
                    type="button"
                    variant="outline"
                    :disabled="publishing"
                    @click="togglePublication"
                >
                    <Spinner v-if="publishing" />
                    <EyeOff v-if="isPublished" aria-hidden="true" />
                    <Eye v-else aria-hidden="true" />
                    {{ isPublished ? 'Unpublish' : 'Publish' }}
                </Button>
            </div>
        </section>

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
                Deleting this record cannot be undone, and any public URL it
                held stops working.
            </p>

            <Button
                type="button"
                variant="destructive"
                @click="deleteOpen = true"
            >
                Delete publication
            </Button>
        </section>

        <DeleteConfirmDialog
            v-model:open="deleteOpen"
            :title="`Delete “${recordName}”?`"
            description="Publication records cannot be restored once they are deleted."
            :action="destroy(record).url"
        />
    </div>
</template>
