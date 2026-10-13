<script setup lang="ts">
import { AlertCircle } from '@lucide/vue';
import { computed, nextTick, onMounted, useTemplateRef, watch } from 'vue';
import { Alert } from '@/components/ui/alert';

type Props = {
    errors: Record<string, string | undefined>;
    labels?: Record<string, string>;
    ids?: Record<string, string>;
    id?: string;
    title?: string;
};

const props = withDefaults(defineProps<Props>(), {
    labels: () => ({}),
    ids: () => ({}),
    id: 'form-error-summary',
    title: 'There is a problem',
});

const humanise = (name: string): string =>
    name
        .replace(/[_-]+/g, ' ')
        .replace(/^\p{Ll}/u, (character) => character.toUpperCase());

const fields = computed(() =>
    Object.entries(props.errors)
        .filter((entry): entry is [string, string] => Boolean(entry[1]))
        .map(([name, message]) => ({
            id: props.ids[name] ?? name,
            label: props.labels[name] ?? humanise(name),
            message,
        })),
);

const summary = useTemplateRef<InstanceType<typeof Alert>>('summary');

const focusSummary = async (): Promise<void> => {
    if (fields.value.length === 0) {
        return;
    }

    await nextTick();
    summary.value?.$el?.focus();
};

onMounted(focusSummary);
watch(fields, focusSummary);
</script>

<template>
    <Alert
        v-if="fields.length > 0"
        :id="id"
        ref="summary"
        variant="destructive"
        tabindex="-1"
    >
        <AlertCircle class="size-4" aria-hidden="true" />
        <h2 class="col-start-2 text-sm font-semibold">{{ title }}</h2>
        <ul class="col-start-2 list-disc space-y-1 pl-4 text-sm">
            <li v-for="field in fields" :key="field.id">
                <a
                    :href="`#${field.id}`"
                    class="underline decoration-2 underline-offset-4"
                >
                    {{ field.label }} — {{ field.message }}
                </a>
            </li>
        </ul>
    </Alert>
</template>
