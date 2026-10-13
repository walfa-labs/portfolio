<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { MailOpen } from '@lucide/vue';
import { computed, useId } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/public/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes/admin';
import {
    index as messagesIndex,
    read as messagesRead,
} from '@/routes/admin/messages';

/*
 * One row of the inbox: `MessageController::recordPayload()`'s flat shape, which
 * is §2.3's `contact_messages` column set.
 */
type ContactMessageRecord = {
    id: number;
    name: string;
    email: string;
    message: string;
    request_type: string | null;
    ip_address: string | null;
    user_agent: string | null;
    created_at: string | null;
    read_at: string | null;
};

/** Laravel's `LengthAwarePaginator` payload, as the controller sends it. */
type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    path: string;
    links: { url: string | null; label: string; active: boolean }[];
};

const props = defineProps<{
    records: Paginator<ContactMessageRecord>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Messages', href: messagesIndex() },
        ],
    },
});

const rows = computed(() => props.records.data);

const uid = useId();
const captionId = `messages-caption-${uid}`;

const caption = computed(
    () =>
        `Contact form submissions, newest first — ${props.records.total} in total, page ${props.records.current_page} of ${props.records.last_page}.`,
);

/*
 * §8.8's triage set. The stored value is the enum the form submits; the label is
 * what the inbox shows. An unknown value falls back to the raw string rather
 * than disappearing.
 */
const REQUEST_TYPE_LABELS: Record<string, string> = {
    access: 'Access',
    rectification: 'Rectification',
    erasure: 'Erasure',
    objection: 'Objection',
    other: 'Other',
};

const requestTypeLabel = (value: string | null): string =>
    value === null || value === ''
        ? '—'
        : (REQUEST_TYPE_LABELS[value] ?? value);

/* UTC and a fixed locale, so the server-rendered HTML and the client's
   hydration agree. */
const formatDateTime = (value: string | null): string => {
    if (value === null) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? '—'
        : date.toLocaleString('en', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
              timeZone: 'UTC',
          });
};

/*
 * The explicit mark-as-read action (§5.8). Opening the list marks nothing; only
 * this visit does, and it stays on the same page so the admin keeps their place.
 */
const markAsRead = (record: ContactMessageRecord): void => {
    router.patch(
        messagesRead.url(record.id),
        {},
        {
            preserveScroll: true,
        },
    );
};
</script>

<template>
    <Head title="Messages · Admin" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Heading
            as="h1"
            title="Messages"
            description="Contact form submissions, newest first. Opening this page marks nothing as read — use the action on a row."
        />

        <!--
            §7.2 (2.1.1) — the table is wider than the column at every viewport,
            so the section is a scrollable region with no focusable content of
            its own beyond the controls. `tabindex="0"` puts it in the tab order
            so it can be scrolled with the keyboard, and it takes its name from
            the caption rather than new copy.
        -->
        <section
            :aria-labelledby="captionId"
            tabindex="0"
            class="overflow-x-auto"
        >
            <table
                class="w-full min-w-[64rem] border-collapse border-2 border-ink text-left text-sm"
            >
                <caption
                    :id="captionId"
                    class="mb-3 text-left text-meta text-muted-foreground"
                >
                    {{
                        caption
                    }}
                </caption>

                <thead class="bg-secondary">
                    <tr class="border-b-2 border-ink">
                        <th scope="col" class="p-3 font-semibold">From</th>
                        <th scope="col" class="p-3 font-semibold">Request</th>
                        <th scope="col" class="p-3 font-semibold">Status</th>
                        <th scope="col" class="p-3 font-semibold">Received</th>
                        <th scope="col" class="p-3 font-semibold">Message</th>
                        <th scope="col" class="p-3 font-semibold">Source</th>
                        <th scope="col" class="p-3 text-right font-semibold">
                            Actions
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <tr
                        v-for="record in rows"
                        :key="record.id"
                        class="border-b border-ink/40 align-top"
                    >
                        <th scope="row" class="p-3 font-medium">
                            <span class="block">{{ record.name }}</span>
                            <a
                                :href="`mailto:${record.email}`"
                                class="text-meta font-normal underline decoration-2 underline-offset-4 hover:text-primary inline-flex min-h-6 items-center"
                            >
                                {{ record.email }}
                            </a>
                        </th>

                        <td class="p-3">
                            <Badge
                                v-if="record.request_type"
                                variant="secondary"
                            >
                                {{ requestTypeLabel(record.request_type) }}
                            </Badge>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>

                        <!--
                            Read state is carried by the word itself, not by the
                            badge's colour (§7.10).
                        -->
                        <td class="p-3">
                            <Badge
                                :variant="
                                    record.read_at ? 'outline' : 'default'
                                "
                            >
                                {{ record.read_at ? 'Read' : 'Unread' }}
                            </Badge>
                        </td>

                        <td class="p-3">
                            <time
                                v-if="record.created_at"
                                :datetime="record.created_at"
                            >
                                {{ formatDateTime(record.created_at) }}
                            </time>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>

                        <!--
                            §7.2 (2.1.1) again, for the message body: a long
                            submission scrolls inside its own focusable region
                            rather than stretching the row past the viewport.
                        -->
                        <td class="p-3">
                            <div
                                class="max-h-40 max-w-[48ch] overflow-y-auto rounded-sm border-2 border-ink/40 bg-card p-2 break-words whitespace-pre-wrap"
                                tabindex="0"
                                role="region"
                                :aria-label="`Message from ${record.name}`"
                            >
                                {{ record.message }}
                            </div>
                        </td>

                        <td class="p-3">
                            <p
                                v-if="record.ip_address"
                                class="font-mono text-meta"
                            >
                                {{ record.ip_address }}
                            </p>
                            <p
                                v-if="record.user_agent"
                                :title="record.user_agent"
                                class="line-clamp-2 max-w-[32ch] text-meta break-words text-muted-foreground"
                            >
                                {{ record.user_agent }}
                            </p>
                            <span
                                v-if="!record.ip_address && !record.user_agent"
                                class="text-muted-foreground"
                            >
                                —
                            </span>
                        </td>

                        <td class="p-3 text-right">
                            <Button
                                type="button"
                                variant="outline"
                                size="icon-sm"
                                @click="markAsRead(record)"
                            >
                                <MailOpen aria-hidden="true" />
                                <span class="sr-only">
                                    Mark the message from {{ record.name }} as
                                    read
                                </span>
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <EmptyState
            v-if="rows.length === 0"
            title="No messages yet."
            description="Contact form submissions will appear here, newest first, as soon as someone uses the contact page."
        />

        <Pagination
            :links="records.links"
            :meta="{
                current_page: records.current_page,
                last_page: records.last_page,
            }"
        />
    </div>
</template>
