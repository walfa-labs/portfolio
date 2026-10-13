<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Eye, EyeOff, Trash2 } from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Pagination from '@/components/public/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { ResourceDescriptor } from '@/lib/resources';
import DeleteConfirmDialog from './DeleteConfirmDialog.vue';
import type { AdminRecord, Paginator } from './resourceUrls';
import { adminUrls, displayName } from './resourceUrls';

/*
 * §5.9 — the filtered, paginated list. It owns the filter form, the
 * "Skip to results" bypass block (§7.12) and the table itself, because the
 * `filters` prop is only meaningful to whoever renders the filter controls.
 *
 * The table is a real `<table>`: a `<caption>`, `<thead>`, `<th scope="col">`
 * and a `<th scope="row">` carrying the first cell, which is also the row's
 * link to edit. No `<div>`-grid table (§7.3).
 *
 * Publish/unpublish is the one §5.10 optimistic case here: the row's status
 * flips locally before the visit and rolls back if the request fails.
 */
const props = defineProps<{
    descriptor: ResourceDescriptor;
    records: Paginator<AdminRecord>;
    filters: Record<string, string | null>;
    selected: number[];
}>();

const emit = defineEmits<{
    'update:selected': [ids: number[]];
}>();

/* The sentinel a filter `<select>` uses for "no filter". Reka's `SelectItem`
   throws on an empty string, so "any" cannot be spelled as one. */
const ANY = '__any__';

const urls = computed(() => adminUrls(props.descriptor.segment));

const uid = useId();
const resultsId = `admin-results-${uid}`;
const captionId = `admin-caption-${uid}`;
const filterId = (name: string): string => `admin-filter-${uid}-${name}`;

const rows = computed(() => props.records.data);

/*
 * The first descriptor column is the row header: its value is the record's
 * name and the link that opens the edit screen. The rest are ordinary cells.
 */
const headColumn = computed(() => props.descriptor.columns[0]);
const bodyColumns = computed(() => props.descriptor.columns.slice(1));

const summary = computed(
    () =>
        `${props.records.total} in total, page ${props.records.current_page} of ${props.records.last_page}`,
);

/* The caption is also the results region's accessible name (§7.3). */
const caption = computed(
    () => `${props.descriptor.pluralLabel} — ${summary.value}`,
);

const filtered = computed(() =>
    Object.values(props.filters).some(
        (value) => value !== null && value !== '',
    ),
);

const emptyTitle = computed(() =>
    filtered.value
        ? `No ${props.descriptor.pluralLabel.toLowerCase()} match these filters.`
        : `No ${props.descriptor.pluralLabel.toLowerCase()} yet.`,
);

/* Filters -------------------------------------------------------------- */

const filterValues = ref<Record<string, string>>({});

const resetFilterValues = (): void => {
    const values: Record<string, string> = {};

    for (const filter of props.descriptor.filters) {
        const current = props.filters[filter.name] ?? '';

        values[filter.name] =
            filter.type === 'select' && current === '' ? ANY : current;
    }

    filterValues.value = values;
};

resetFilterValues();
watch(() => props.filters, resetFilterValues, { deep: true });

const setFilter = (name: string, value: string): void => {
    filterValues.value = { ...filterValues.value, [name]: value };
};

const applyFilters = (): void => {
    const query: Record<string, string> = {};

    for (const filter of props.descriptor.filters) {
        const value = (filterValues.value[filter.name] ?? '').trim();

        if (value !== '' && value !== ANY) {
            query[filter.name] = value;
        }
    }

    /*
     * A plain GET, so the filter state is a real URL the admin can share and
     * step back through — the same behaviour the form's no-JS `method="get"`
     * fallback has. A partial reload keeps the visit to the three props that
     * change (§5.9).
     */
    router.get(urls.value.index, query, {
        only: ['records', 'filters', 'meta'],
        preserveScroll: true,
    });
};

const clearFilters = (): void => {
    router.get(
        urls.value.index,
        {},
        {
            only: ['records', 'filters', 'meta'],
            preserveScroll: true,
        },
    );
};

/* Selection ------------------------------------------------------------ */

const allSelected = computed(
    () =>
        rows.value.length > 0 &&
        rows.value.every((record) => props.selected.includes(record.id)),
);

const someSelected = computed(() =>
    rows.value.some((record) => props.selected.includes(record.id)),
);

const toggleRow = (id: number): void => {
    emit(
        'update:selected',
        props.selected.includes(id)
            ? props.selected.filter((selected) => selected !== id)
            : [...props.selected, id],
    );
};

const toggleAll = (): void => {
    const pageIds = rows.value.map((record) => record.id);

    emit(
        'update:selected',
        allSelected.value
            ? props.selected.filter((id) => !pageIds.includes(id))
            : Array.from(new Set([...props.selected, ...pageIds])),
    );
};

/* Publish / unpublish -------------------------------------------------- */

const pendingStatus = ref<Record<number, string>>({});

/* The seven content types carry a status; a tag has none (§2.5), which is
   what keeps the publish control off the tag list. */
const isPublishable = (record: AdminRecord): boolean =>
    typeof record.status === 'string';

const statusOf = (record: AdminRecord): string =>
    pendingStatus.value[record.id] ??
    (typeof record.status === 'string' ? record.status : 'draft');

const toggleStatus = (record: AdminRecord): void => {
    const publishing = statusOf(record) !== 'published';
    const previous = pendingStatus.value[record.id];

    pendingStatus.value = {
        ...pendingStatus.value,
        [record.id]: publishing ? 'published' : 'draft',
    };

    router.patch(
        (publishing ? urls.value.publish : urls.value.unpublish)(record.id),
        {},
        {
            preserveScroll: true,
            onError: () => {
                const rolledBack = { ...pendingStatus.value };

                if (previous === undefined) {
                    delete rolledBack[record.id];
                } else {
                    rolledBack[record.id] = previous;
                }

                pendingStatus.value = rolledBack;
            },
        },
    );
};

/* Fresh props are the authority; the overlay has done its job. */
watch(
    () => props.records,
    () => {
        pendingStatus.value = {};
    },
);

/* Delete --------------------------------------------------------------- */

const deleteTarget = ref<AdminRecord | null>(null);
const deleteOpen = ref(false);

const askDelete = (record: AdminRecord): void => {
    deleteTarget.value = record;
    deleteOpen.value = true;
};

const destroyUrl = computed(() =>
    deleteTarget.value
        ? urls.value.destroy(deleteTarget.value.id)
        : urls.value.index,
);

const deleteTitle = computed(() =>
    deleteTarget.value
        ? `Delete "${displayName(deleteTarget.value)}"?`
        : 'Delete this record?',
);

const deleteDescription = computed(
    () =>
        `${props.descriptor.label} records cannot be restored once they are deleted.`,
);

const handleConfirmed = (): void => {
    if (deleteTarget.value === null) {
        return;
    }

    emit(
        'update:selected',
        props.selected.filter((id) => id !== deleteTarget.value?.id),
    );
};

/* Cells ---------------------------------------------------------------- */

const cellText = (record: AdminRecord, key: string): string => {
    const value = record[key];

    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : '—';
};

/* UTC, so the server-rendered HTML and the client's hydration agree. */
const formatDateTime = (value: unknown): string => {
    if (typeof value !== 'string' && typeof value !== 'number') {
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

const tagsOf = (
    record: AdminRecord,
): { id: number; name: string; slug: string }[] =>
    Array.isArray(record.tags) ? record.tags : [];

const isDateColumn = (key: string): boolean =>
    key === 'published_at' || key === 'updated_at' || key === 'created_at';
</script>

<template>
    <div class="space-y-4">
        <form
            class="flex flex-wrap items-end gap-3"
            method="get"
            :action="urls.index"
            @submit.prevent="applyFilters"
        >
            <div
                v-for="filter in descriptor.filters"
                :key="filter.name"
                class="grid gap-2"
            >
                <Label :for="filterId(filter.name)">{{ filter.label }}</Label>

                <Select
                    v-if="filter.type === 'select'"
                    :name="filter.name"
                    :model-value="filterValues[filter.name]"
                    @update:model-value="
                        (value) => setFilter(filter.name, String(value ?? ''))
                    "
                >
                    <SelectTrigger :id="filterId(filter.name)" class="w-44">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ANY">Any</SelectItem>
                        <SelectItem
                            v-for="option in filter.options ?? []"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Input
                    v-else
                    :id="filterId(filter.name)"
                    v-model="filterValues[filter.name]"
                    class="w-56"
                    type="search"
                />
            </div>

            <Button type="submit" variant="outline">Apply filters</Button>
            <Button type="button" variant="ghost" @click="clearFilters">
                Clear
            </Button>
        </form>

        <!--
            §7.12 — a bypass block after the filters. Visually hidden until
            focused, like the shell's own skip link, and it moves focus into the
            results region rather than only scrolling to it.
        -->
        <a
            :href="`#${resultsId}`"
            class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-modal focus:rounded-sm focus:border-2 focus:border-ink focus:bg-background focus:px-4 focus:py-2 focus:text-foreground"
        >
            Skip to results
        </a>

        <section
            :id="resultsId"
            tabindex="-1"
            :aria-labelledby="captionId"
            class="space-y-3"
        >
            <div class="overflow-x-auto">
                <table
                    class="w-full border-separate border-spacing-0 border-2 border-ink text-left text-sm"
                >
                    <caption
                        :id="captionId"
                        class="mb-3 text-left text-meta text-muted-foreground"
                    >
                        {{
                            caption
                        }}
                    </caption>

                    <thead class="sticky top-0 z-sticky bg-secondary">
                        <tr>
                            <th
                                scope="col"
                                class="border-b-2 border-ink px-4 py-2 text-left font-semibold"
                            >
                                <div class="flex items-center gap-2">
                                    <Checkbox
                                        :model-value="
                                            someSelected && !allSelected
                                                ? 'indeterminate'
                                                : allSelected
                                        "
                                        :aria-label="`Select all ${descriptor.pluralLabel.toLowerCase()}`"
                                        @update:model-value="toggleAll"
                                    />
                                    <span>{{ headColumn.label }}</span>
                                </div>
                            </th>

                            <th
                                v-for="column in bodyColumns"
                                :key="column.key"
                                scope="col"
                                class="border-b-2 border-ink px-4 py-2 font-semibold"
                                :class="
                                    column.numeric ? 'text-right' : 'text-left'
                                "
                            >
                                {{ column.label }}
                            </th>

                            <th
                                scope="col"
                                class="border-b-2 border-ink px-4 py-2 text-right font-semibold"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="record in rows" :key="record.id">
                            <th
                                scope="row"
                                class="border-b-hairline border-ink/40 px-4 py-2 text-left font-medium"
                            >
                                <div class="flex items-center gap-2">
                                    <Checkbox
                                        :model-value="
                                            selected.includes(record.id)
                                        "
                                        :aria-label="`Select ${displayName(record)}`"
                                        @update:model-value="
                                            toggleRow(record.id)
                                        "
                                    />
                                    <Link
                                        :href="urls.edit(record.id)"
                                        class="inline-flex min-h-6 items-center underline decoration-2 underline-offset-4"
                                    >
                                        {{ cellText(record, headColumn.key) }}
                                    </Link>
                                </div>
                            </th>

                            <td
                                v-for="column in bodyColumns"
                                :key="column.key"
                                class="border-b-hairline border-ink/40 px-4 py-2"
                                :class="
                                    column.numeric
                                        ? 'text-right tabular-nums'
                                        : ''
                                "
                            >
                                <template v-if="column.key === 'status'">
                                    <Badge
                                        :variant="
                                            statusOf(record) === 'published'
                                                ? 'default'
                                                : 'outline'
                                        "
                                    >
                                        {{
                                            statusOf(record) === 'published'
                                                ? 'Published'
                                                : 'Draft'
                                        }}
                                    </Badge>
                                </template>

                                <template v-else-if="column.key === 'tags'">
                                    <ul
                                        v-if="tagsOf(record).length > 0"
                                        class="flex flex-wrap gap-2"
                                    >
                                        <li
                                            v-for="tag in tagsOf(record)"
                                            :key="tag.id"
                                        >
                                            <Badge variant="secondary">
                                                {{ tag.name }}
                                            </Badge>
                                        </li>
                                    </ul>
                                    <span v-else class="text-muted-foreground">
                                        —
                                    </span>
                                </template>

                                <template v-else-if="isDateColumn(column.key)">
                                    <time
                                        v-if="record[column.key]"
                                        :datetime="String(record[column.key])"
                                    >
                                        {{ formatDateTime(record[column.key]) }}
                                    </time>
                                    <span v-else class="text-muted-foreground">
                                        —
                                    </span>
                                </template>

                                <template
                                    v-else-if="column.key === 'is_placeholder'"
                                >
                                    {{ record.is_placeholder ? 'Yes' : 'No' }}
                                </template>

                                <template v-else>
                                    {{ cellText(record, column.key) }}
                                </template>
                            </td>

                            <td
                                class="border-b-hairline border-ink/40 px-4 py-2"
                            >
                                <div
                                    class="flex items-center justify-end gap-1"
                                >
                                    <Button
                                        v-if="isPublishable(record)"
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        @click="toggleStatus(record)"
                                    >
                                        <Eye
                                            v-if="
                                                statusOf(record) !== 'published'
                                            "
                                            aria-hidden="true"
                                        />
                                        <EyeOff v-else aria-hidden="true" />
                                        <span class="sr-only">
                                            {{
                                                statusOf(record) === 'published'
                                                    ? `Unpublish ${displayName(record)}`
                                                    : `Publish ${displayName(record)}`
                                            }}
                                        </span>
                                    </Button>

                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        @click="askDelete(record)"
                                    >
                                        <Trash2
                                            class="text-destructive"
                                            aria-hidden="true"
                                        />
                                        <span class="sr-only">
                                            Delete {{ displayName(record) }}
                                        </span>
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <EmptyState
                v-if="rows.length === 0"
                :title="emptyTitle"
                description="Nothing is listed here yet, so there is nothing to filter or select."
            />

            <Pagination
                :links="records.links"
                :meta="{
                    current_page: records.current_page,
                    last_page: records.last_page,
                }"
            />
        </section>

        <DeleteConfirmDialog
            v-model:open="deleteOpen"
            :title="deleteTitle"
            :description="deleteDescription"
            :action="destroyUrl"
            @confirmed="handleConfirmed"
        />
    </div>
</template>
