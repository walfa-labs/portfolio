<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, useId } from 'vue';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import InputError from '@/components/InputError.vue';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { ResourceDescriptor, ResourceField } from '@/lib/resources';
import TagPicker from './TagPicker.vue';
import type { AdminRecord } from './resourceUrls';
import { adminUrls } from './resourceUrls';

/*
 * §5.10 — one schema-driven form for all eight resources. The descriptor
 * supplies the fields; this component supplies the label, the help text, the
 * error association and the `aria-invalid` state, once.
 *
 * `slug` is deliberately absent: it is derived server-side and shown read-only
 * on the edit screen, and the request refuses it (§5.9).
 *
 * Validation is server-side only — a failed submit answers 422 and Inertia
 * hands the messages back on `form.errors`, which `FormErrorSummary` focuses
 * and `InputError` renders per field. §5.10's Precognition spike is not taken
 * here: its exact wiring is marked UNVERIFIED there, and plain 422-on-submit is
 * the sanctioned fallback with no loss of correctness.
 *
 * Every datetime is read and written in UTC, so the value the admin sees is the
 * value that round-trips and the server-rendered input cannot disagree with the
 * hydrated one.
 */
type FormValue = string | number | boolean | number[] | null;
type FormValues = Record<string, FormValue>;

type TagOption = {
    id: number;
    name: string;
    slug: string;
};

const props = defineProps<{
    descriptor: ResourceDescriptor;
    record?: AdminRecord | null;
    action: string;
    method: 'post' | 'patch';
    submitLabel: string;
    lastCreated?: Record<string, unknown> | null;
}>();

/* Reka's `SelectItem` throws on an empty string, so "none" needs a sentinel. */
const NONE = '__none__';

const urls = computed(() => adminUrls(props.descriptor.segment));

/*
 * The tag list is a page prop, not a prop of this component: the §5.10 field
 * descriptor has no channel for it, and the create/edit pages are the only
 * place that knows which resource is being edited.
 */
const page = usePage<{ tags?: TagOption[] }>();
const allTags = computed(() => page.props.tags ?? []);

const uid = useId();
const fieldId = (field: ResourceField): string =>
    `admin-field-${uid}-${field.name}`;
const helpId = (field: ResourceField): string => `${fieldId(field)}-help`;
const errorId = (field: ResourceField): string => `${fieldId(field)}-error`;

const toUtcDateTime = (value: unknown): string => {
    if (typeof value !== 'string' || value === '') {
        return '';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '' : date.toISOString().slice(0, 16);
};

const initialValue = (field: ResourceField): FormValue => {
    const current = props.record?.[field.name];

    switch (field.type) {
        case 'checkbox':
            return current === true;
        case 'tags':
            return Array.isArray(current)
                ? current.filter((id): id is number => typeof id === 'number')
                : [];
        case 'select':
            if (current === null || current === undefined || current === '') {
                return field.required ? '' : NONE;
            }

            return String(current);
        case 'number':
            return current === null || current === undefined || current === ''
                ? ''
                : Number(current);
        case 'datetime':
            return toUtcDateTime(current);
        default:
            return current === null || current === undefined
                ? ''
                : String(current);
    }
};

const initialData = (): FormValues => {
    const values: FormValues = {};

    for (const field of props.descriptor.fields) {
        values[field.name] = initialValue(field);
    }

    return values;
};

const form = useForm<FormValues>(initialData());

/*
 * Two members the payload must not carry as-is. An empty `number` is dropped
 * rather than sent: `sometimes|required|integer` fails on an empty string, and
 * "left blank" means "leave it alone". A `select` sentinel becomes the empty
 * string, which the server reads as null.
 */
form.transform((data) => {
    const payload: FormValues = {};

    for (const field of props.descriptor.fields) {
        const value = data[field.name];

        if (field.type === 'number' && (value === '' || value === null)) {
            continue;
        }

        payload[field.name] =
            field.type === 'select' && value === NONE ? '' : value;
    }

    return payload;
});

const submit = (): void => {
    form.submit(props.method, props.action, { preserveScroll: true });
};

/* WCAG 3.3.7 — the previous save, offered but never applied automatically. */
const hasPrefill = computed(() => {
    const last = props.lastCreated;

    return last !== null && last !== undefined && Object.keys(last).length > 0;
});

const prefillStatus = ref('');

const coercePrefill = (field: ResourceField, value: unknown): FormValue => {
    switch (field.type) {
        case 'checkbox':
            return value === true;
        case 'tags':
            return Array.isArray(value)
                ? value.filter((id): id is number => typeof id === 'number')
                : [];
        case 'number':
            return typeof value === 'number' ? value : Number(value);
        case 'datetime':
            return toUtcDateTime(value);
        default:
            return String(value);
    }
};

const applyPrefill = (): void => {
    const last = props.lastCreated;

    if (last === null || last === undefined) {
        return;
    }

    for (const field of props.descriptor.fields) {
        const value = last[field.name];

        if (!(field.name in last) || value === null || value === undefined) {
            continue;
        }

        form[field.name] = coercePrefill(field, value);
    }

    prefillStatus.value = 'Prefilled from the last entry.';
};

const labels = computed(() =>
    Object.fromEntries(
        props.descriptor.fields.map((field) => [field.name, field.label]),
    ),
);

const ids = computed(() =>
    Object.fromEntries(
        props.descriptor.fields.map((field) => [field.name, fieldId(field)]),
    ),
);

const inputType = (field: ResourceField): string => {
    switch (field.type) {
        case 'url':
            return 'url';
        case 'number':
            return 'number';
        case 'date':
            return 'date';
        case 'datetime':
            return 'datetime-local';
        default:
            return 'text';
    }
};

const describedBy = (field: ResourceField): string | undefined => {
    const described: string[] = [];

    if (field.help) {
        described.push(helpId(field));
    }

    if (form.errors[field.name]) {
        described.push(errorId(field));
    }

    return described.length > 0 ? described.join(' ') : undefined;
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <FormErrorSummary :errors="form.errors" :labels="labels" :ids="ids" />

        <p class="text-meta text-muted-foreground">
            Fields marked with an asterisk (*) are required.
        </p>

        <div v-if="hasPrefill" class="flex flex-wrap items-center gap-3">
            <Button type="button" variant="outline" @click="applyPrefill">
                Prefill from last entry
            </Button>
            <p class="text-meta text-muted-foreground">
                Copies the fields that repeat between entries. Nothing is copied
                until you press it.
            </p>
            <p role="status" class="sr-only">{{ prefillStatus }}</p>
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div
                v-for="field in descriptor.fields"
                :key="field.name"
                class="grid content-start gap-2"
                :class="field.wide ? 'sm:col-span-2' : ''"
            >
                <!-- A checkbox is its own label; the help and the error sit
                     beside it rather than above the control. -->
                <template v-if="field.type === 'checkbox'">
                    <Label
                        :for="fieldId(field)"
                        class="flex items-center gap-3"
                    >
                        <Checkbox
                            :id="fieldId(field)"
                            :model-value="Boolean(form[field.name])"
                            :aria-describedby="describedBy(field)"
                            :aria-invalid="Boolean(form.errors[field.name])"
                            @update:model-value="
                                (value) => (form[field.name] = value === true)
                            "
                        />
                        <span>
                            {{ field.label }}
                            <span v-if="field.required" aria-hidden="true"
                                >*</span
                            >
                            <span v-if="field.required" class="sr-only"
                                >(required)</span
                            >
                        </span>
                    </Label>
                </template>

                <!-- Grouped controls get a fieldset and a legend (§7.7). -->
                <template v-else-if="field.type === 'tags'">
                    <fieldset
                        class="grid gap-2"
                        :aria-describedby="describedBy(field)"
                    >
                        <legend class="text-sm font-medium">
                            {{ field.label }}
                            <span v-if="field.required" aria-hidden="true"
                                >*</span
                            >
                            <span v-if="field.required" class="sr-only"
                                >(required)</span
                            >
                        </legend>

                        <TagPicker
                            :tags="allTags"
                            :model-value="
                                Array.isArray(form[field.name])
                                    ? (form[field.name] as number[])
                                    : []
                            "
                            @update:model-value="
                                (selected) => (form[field.name] = selected)
                            "
                        />
                    </fieldset>
                </template>

                <template v-else>
                    <Label :for="fieldId(field)">
                        {{ field.label }}
                        <span v-if="field.required" aria-hidden="true">*</span>
                        <span v-if="field.required" class="sr-only"
                            >(required)</span
                        >
                    </Label>

                    <Select
                        v-if="field.type === 'select'"
                        :model-value="String(form[field.name] ?? '')"
                        @update:model-value="
                            (value) => (form[field.name] = String(value ?? ''))
                        "
                    >
                        <SelectTrigger
                            :id="fieldId(field)"
                            class="w-full"
                            :aria-invalid="
                                Boolean(form.errors[field.name]) || undefined
                            "
                            :aria-describedby="describedBy(field)"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-if="!field.required" :value="NONE">
                                None
                            </SelectItem>
                            <SelectItem
                                v-for="option in field.options ?? []"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Textarea
                        v-else-if="field.type === 'textarea'"
                        :id="fieldId(field)"
                        :model-value="String(form[field.name] ?? '')"
                        :required="field.required"
                        :aria-invalid="
                            Boolean(form.errors[field.name]) || undefined
                        "
                        :aria-describedby="describedBy(field)"
                        @update:model-value="
                            (value) => (form[field.name] = String(value))
                        "
                    />

                    <Input
                        v-else
                        :id="fieldId(field)"
                        :type="inputType(field)"
                        :model-value="String(form[field.name] ?? '')"
                        :required="field.required"
                        :aria-invalid="
                            Boolean(form.errors[field.name]) || undefined
                        "
                        :aria-describedby="describedBy(field)"
                        @update:model-value="
                            (value) => (form[field.name] = String(value))
                        "
                    />
                </template>

                <p
                    v-if="field.help"
                    :id="helpId(field)"
                    class="text-meta text-muted-foreground"
                >
                    {{ field.help }}
                </p>

                <InputError
                    :id="errorId(field)"
                    :message="form.errors[field.name]"
                />
            </div>

            <!--
                §5.9 — the slug is displayed read-only on the edit screen and is
                never a form control: it is derived from the title on create and
                never rewritten afterwards.
            -->
            <div v-if="record?.slug" class="grid content-start gap-2">
                <p class="text-sm font-medium">Slug</p>
                <p class="font-mono text-meta break-all">
                    {{ record.slug }}
                </p>
                <p class="text-meta text-muted-foreground">
                    Derived from the title when the record was created. It never
                    changes, and it is not part of this form.
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                {{ submitLabel }}
            </Button>

            <!-- The escape path is never disabled while the submit is. -->
            <Button as-child variant="secondary">
                <Link :href="urls.index">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
