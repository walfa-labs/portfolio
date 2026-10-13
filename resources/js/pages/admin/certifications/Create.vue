<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CertificationController from '@/actions/App/Http/Controllers/Admin/CertificationController';
import Heading from '@/components/Heading.vue';
import { dashboard } from '@/routes/admin';
import {
    create as certificationsCreate,
    index as certificationsIndex,
} from '@/routes/admin/certifications';
import { certifications } from '@/lib/resources';
import ResourceForm from '@/pages/admin/partials/ResourceForm.vue';
import type { AdminRecord } from '@/pages/admin/partials/resourceUrls';

/*
 * §5.9 — the blank certification form. `record` is null here and `lastCreated`
 * is the previous save of this type, read server-side so it survives Inertia
 * navigation; the form offers a "Prefill from last entry" button and populates
 * nothing until the admin activates it (WCAG 3.3.7).
 *
 * The slug is deliberately absent from the descriptor: it is derived from the
 * title on create and never changes afterwards (§5.9).
 */
defineProps<{
    record: AdminRecord | null;
    lastCreated?: Record<string, unknown> | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Certifications', href: certificationsIndex() },
            { title: 'New', href: certificationsCreate() },
        ],
    },
});
</script>

<template>
    <Head title="New certification · Admin" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Heading
            as="h1"
            title="New certification"
            description="Add a professional credential. The slug is derived from the title when the record is created and never changes afterwards."
        />

        <ResourceForm
            :descriptor="certifications"
            :record="record"
            :action="CertificationController.store.form().action"
            method="post"
            :last-created="lastCreated"
            submit-label="Save certification"
        />
    </div>
</template>
