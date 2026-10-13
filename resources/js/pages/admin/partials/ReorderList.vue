<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import { Button } from '@/components/ui/button';
import type { ResourceDescriptor } from '@/lib/resources';
import type { AdminRecord, Paginator } from './resourceUrls';
import { adminUrls, displayName, rowsOf } from './resourceUrls';

/*
 * §5.9 — reordering is move up / move down buttons, never a drag handle. §7.2
 * (2.5.7) makes that binding: a drag-only reorder is not keyboard-operable, and
 * no draggable control ships in v1.
 *
 * The `PATCH reorder` saves itself (§5.9). The move is applied locally first
 * and rolled back if the request fails, which is the §5.10 optimistic case for
 * reorder; `reordered` fires with the new order so the page can react, and the
 * page must not send the request again.
 *
 * The order is the order of the rows handed in — the admin's own page — so the
 * list is only meaningful when the whole collection fits on one page.
 */
const props = defineProps<{
    descriptor: ResourceDescriptor;
    records: Paginator<AdminRecord> | AdminRecord[];
}>();

const emit = defineEmits<{
    reordered: [ids: number[]];
}>();

const urls = computed(() => adminUrls(props.descriptor.segment));

/* `writings` and `tags` have no `reorder` route, so there is nothing to render. */
const reorderUrl = computed(() => urls.value.reorder?.() ?? null);

const headingId = `reorder-heading-${useId()}`;

const items = ref<AdminRecord[]>([]);
const message = ref('');

watch(
    () => props.records,
    (records) => {
        items.value = [...rowsOf(records)];
    },
    { immediate: true },
);

const move = (index: number, offset: number): void => {
    const target = index + offset;

    if (
        target < 0 ||
        target >= items.value.length ||
        reorderUrl.value === null
    ) {
        return;
    }

    const next = [...items.value];
    const [moved] = next.splice(index, 1);
    next.splice(target, 0, moved);
    items.value = next;

    const ids = next.map((record) => record.id);

    emit('reordered', ids);
    message.value = `${displayName(moved)} moved to position ${target + 1} of ${ids.length}.`;

    router.patch(
        reorderUrl.value,
        { ids },
        {
            preserveScroll: true,
            onError: () => {
                items.value = [...rowsOf(props.records)];
                message.value = 'The new order was not saved.';
            },
        },
    );
};
</script>

<template>
    <section
        v-if="reorderUrl"
        :aria-labelledby="headingId"
        class="space-y-3 rounded-sm border-2 border-ink bg-card p-4"
    >
        <h2 :id="headingId" class="text-sm font-semibold">Order</h2>

        <p class="text-meta text-muted-foreground">
            Use the arrow buttons to move a record. The new order saves
            immediately.
        </p>

        <p role="status" class="sr-only">{{ message }}</p>

        <ol class="space-y-2">
            <li
                v-for="(record, index) in items"
                :key="record.id"
                class="flex items-center gap-3 rounded-sm border-2 border-ink bg-background px-3 py-2"
            >
                <span class="font-mono text-meta tabular-nums">
                    {{ index + 1 }}
                </span>

                <span class="min-w-0 flex-1 truncate text-sm">
                    {{ displayName(record) }}
                </span>

                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    :disabled="index === 0"
                    @click="move(index, -1)"
                >
                    <ArrowUp aria-hidden="true" />
                    <span class="sr-only"
                        >Move {{ displayName(record) }} up</span
                    >
                </Button>

                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    :disabled="index === items.length - 1"
                    @click="move(index, 1)"
                >
                    <ArrowDown aria-hidden="true" />
                    <span class="sr-only"
                        >Move {{ displayName(record) }} down</span
                    >
                </Button>
            </li>
        </ol>
    </section>
</template>
