<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref, useId } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create as createTag } from '@/routes/admin/tags';

/*
 * §5.9 — attaching tags happens here, and the form's payload is `tag_ids:
 * int[]` (decision 17), never the API's JSON:API relationship shape.
 *
 * A checkbox group with a filter box, not a combobox: every tag is a real
 * checkbox with its own `<label for>`, so the whole set is reachable by Tab and
 * arrow-free, and the group's name comes from the `<fieldset>`/`<legend>`
 * `ResourceForm.vue` renders around it. Placed on its own, wrap it in one.
 *
 * The `tags` prop is the whole tag table, supplied by the page. Nothing is
 * created here: §5.9's "creates on the fly" would need a create endpoint the
 * prop contract does not carry, so the picker links to `/admin/tags/create`
 * instead and the admin returns to the form.
 */
type TagOption = {
    id: number;
    name: string;
    slug: string;
};

const props = defineProps<{
    tags: TagOption[];
    modelValue: number[];
}>();

const emit = defineEmits<{
    'update:modelValue': [ids: number[]];
}>();

const uid = useId();
const filterId = `tag-filter-${uid}`;
const checkboxId = (id: number): string => `tag-${uid}-${id}`;

const query = ref('');

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (needle === '') {
        return props.tags;
    }

    return props.tags.filter(
        (tag) =>
            tag.name.toLowerCase().includes(needle) ||
            tag.slug.toLowerCase().includes(needle),
    );
});

const isSelected = (id: number): boolean => props.modelValue.includes(id);

const toggle = (id: number, checked: boolean): void => {
    emit(
        'update:modelValue',
        checked
            ? Array.from(new Set([...props.modelValue, id]))
            : props.modelValue.filter((selected) => selected !== id),
    );
};
</script>

<template>
    <div class="space-y-3">
        <div class="grid gap-2">
            <Label :for="filterId">Filter tags</Label>
            <Input
                :id="filterId"
                v-model="query"
                type="search"
                placeholder="Type a name or slug"
            />
        </div>

        <p v-if="filtered.length === 0" class="text-meta text-muted-foreground">
            No tag matches "{{ query }}".
        </p>

        <ul v-else class="flex max-h-64 flex-wrap gap-2 overflow-y-auto">
            <li v-for="tag in filtered" :key="tag.id">
                <Label
                    :for="checkboxId(tag.id)"
                    class="flex items-center gap-2 rounded-sm border-2 border-ink bg-card px-2 py-1 font-normal"
                >
                    <Checkbox
                        :id="checkboxId(tag.id)"
                        :model-value="isSelected(tag.id)"
                        @update:model-value="
                            (value) => toggle(tag.id, value === true)
                        "
                    />
                    <span>{{ tag.name }}</span>
                </Label>
            </li>
        </ul>

        <p class="text-meta text-muted-foreground">
            <Link
                :href="createTag.url()"
                class="underline decoration-2 underline-offset-4 inline-flex min-h-6 items-center"
            >
                Create a new tag
            </Link>
        </p>
    </div>
</template>
