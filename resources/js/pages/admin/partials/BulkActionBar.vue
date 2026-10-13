<script setup lang="ts">
import { X } from '@lucide/vue';
import { Button } from '@/components/ui/button';

/*
 * §5.9 — the bar appears when rows are selected and shows the count. It is a
 * floating slab rather than a table row, so it keeps the §6.3 hard shadow; the
 * buttons inside it are ordinary `ui/button`s.
 *
 * The bar owns no selection state: `count` and `actions` come from the page,
 * and every press is emitted as its action's `value`. `'clear'` is reserved for
 * the clear-selection control the bar renders itself.
 */
type BulkAction = {
    value: string;
    label: string;
    /** Renders `variant="destructive"`; a destructive action always confirms (§6.7). */
    destructive?: boolean;
};

defineProps<{
    count: number;
    actions: BulkAction[];
}>();

const emit = defineEmits<{
    action: [value: string];
}>();
</script>

<template>
    <div
        v-if="count > 0"
        role="region"
        aria-label="Bulk actions"
        class="sticky bottom-4 z-sticky flex flex-wrap items-center gap-3 rounded-sm border-2 border-ink bg-secondary px-4 py-3 shadow-hard-sm"
    >
        <p role="status" class="text-sm font-semibold tabular-nums">
            {{ count }} selected
        </p>

        <ul class="flex flex-wrap items-center gap-2">
            <li v-for="action in actions" :key="action.value">
                <Button
                    type="button"
                    size="sm"
                    :variant="action.destructive ? 'destructive' : 'outline'"
                    @click="emit('action', action.value)"
                >
                    {{ action.label }}
                </Button>
            </li>
        </ul>

        <Button
            type="button"
            size="sm"
            variant="ghost"
            class="ml-auto"
            @click="emit('action', 'clear')"
        >
            <X aria-hidden="true" />
            Clear selection
        </Button>
    </div>
</template>
