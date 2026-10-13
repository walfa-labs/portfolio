<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PublicationController from '@/actions/App/Http/Controllers/Admin/PublicationController';
import Heading from '@/components/Heading.vue';
import { publications } from '@/lib/resources';
import ResourceForm from '@/pages/admin/partials/ResourceForm.vue';
import { dashboard } from '@/routes/admin';
import { create, index } from '@/routes/admin/publications';

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
            { title: 'Publications', href: index() },
            { title: 'New publication', href: create() },
        ],
    },
});

const descriptor = publications;

const action = PublicationController.store.form().action;
</script>

<template>
    <Head title="New publication · Admin">
        <!-- §5.11 layer 2: the header cannot be dropped, this cannot be
             forgotten by a client-side render. -->
        <meta head-key="robots" name="robots" content="noindex,nofollow" />
    </Head>

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Heading
            as="h1"
            title="New publication"
            description="The slug is derived from the title once, on save, and never changes afterwards."
        />

        <ResourceForm
            :descriptor="descriptor"
            :record="null"
            :action="action"
            method="post"
            submit-label="Create publication"
            :last-created="lastCreated"
        />
    </div>
</template>
