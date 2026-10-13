<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import WritingController from '@/actions/App/Http/Controllers/Admin/WritingController';
import Heading from '@/components/Heading.vue';
import { writings } from '@/lib/resources';
import ResourceForm from '@/pages/admin/partials/ResourceForm.vue';
import { dashboard } from '@/routes/admin';
import { create, index } from '@/routes/admin/writings';

/*
 * §5.9 — the blank form. `record` is `null` and `lastCreated` is the previous
 * save of the same type, read server-side by `AdminController::create()` so it
 * survives Inertia navigation; `ResourceForm.vue` offers it behind a "Prefill
 * from last entry" button and populates nothing on its own (§7.2, WCAG 3.3.7).
 *
 * The action comes from the generated `store.form()` (§5.12): it carries the
 * POST target and the verb the form must spoof, so neither can drift from the
 * route.
 */
defineProps<{
    record: null;
    lastCreated: Record<string, unknown> | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Writings', href: index() },
            { title: 'New writing', href: create() },
        ],
    },
});

const descriptor = writings;

const action = WritingController.store.form().action;
</script>

<template>
    <Head title="New writing · Admin">
        <!-- §5.11 layer 2: the header cannot be dropped, this cannot be
             forgotten by a client-side render. -->
        <meta head-key="robots" name="robots" content="noindex,nofollow" />
    </Head>

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Heading
            as="h1"
            title="New writing"
            description="The slug is derived from the title once, on save, and never changes afterwards. The body is Markdown, rendered to HTML and timed when the record is saved."
        />

        <ResourceForm
            :descriptor="descriptor"
            :record="null"
            :action="action"
            method="post"
            submit-label="Create writing"
            :last-created="lastCreated"
        />
    </div>
</template>

<style scoped>
/*
 * `ui/textarea` ships `field-sizing-content`, so the control grows to fit its
 * whole value. `body` holds a Markdown document, and an unclamped control would
 * grow to the length of the post and push the submit button past the fold
 * (§5.10, §7.2). Clamped to a screenful with its own scrollbar. The short
 * textareas (`summary`, `excerpt`, `meta_description`) never reach the cap, so
 * their content-sizing is unchanged.
 */
:deep([data-slot='textarea']) {
    max-height: 24rem;
    overflow-y: auto;
}
</style>
