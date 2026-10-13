<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, useId } from 'vue';
import InputError from '@/components/InputError.vue';
import ResponsiveImage from '@/components/media/ResponsiveImage.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

/*
 * §5.8/§9.10 — the standalone media upload surface. It renders the `alt` input
 * and the "Decorative (no alt text)" checkbox §5.10 requires, posts the file to
 * `POST /admin/media`, and offers the two follow-up actions the same route set
 * pins: re-run the variant job, and remove the row.
 *
 * The pair is not optional decoration. §9.6's request refuses a `cover` or
 * `inline` upload that carries neither a non-empty `alt` nor `decorative: true`,
 * and §9.2 stores `''` for the checkbox so "decorative" is a recorded decision
 * rather than an inference from absence.
 */
type MediaVariant = {
    url: string;
    width: number;
    height: number;
    mime?: string;
};

/* The §9.10 response shape, and the row an edit screen already holds. */
type UploadedMedia = {
    id: string;
    collection: string;
    status?: string | null;
    url?: string | null;
    alt?: string | null;
    variants?: Record<string, MediaVariant> | null;
};

type Props = {
    /* The canonical singular morph alias, e.g. `writing` (§2.5). */
    mediableType: string;
    mediableId: string | number;
    /* The collection the upload belongs to; the request rejects anything else (§9.6). */
    collection: string;
    /* Overrides the label derived from `collection`. */
    label?: string;
    /* The row already in this slot, when there is one. */
    media?: UploadedMedia | null;
};

const props = withDefaults(defineProps<Props>(), {
    label: undefined,
    media: null,
});

/*
 * The §3.2/§9.10 URIs are written out rather than called through Wayfinder.
 * `resources/js/routes/admin/` has no `media` module until the phase-8 route set
 * is generated, and importing a module that does not exist fails
 * `npm run types:check` outright; these are the same strings the generator
 * would emit.
 */
const STORE_URL = '/admin/media';
const regenerateUrl = (id: string): string => `/admin/media/${id}/regenerate`;
const destroyUrl = (id: string): string => `/admin/media/${id}`;

const COLLECTION_LABELS: Record<string, string> = {
    cover: 'Cover image',
    inline: 'In-body image',
    avatar: 'Avatar',
    badge: 'Badge',
    logo: 'Logo',
    screenshot: 'Screenshot',
    cv: 'CV (PDF)',
    pdf: 'PDF',
};

/*
 * §9.6's allow-list decides what the picker offers; the request and the job
 * re-check the bytes, so this is convenience and not the gate.
 */
const PDF_COLLECTIONS = ['cv', 'pdf'];

const uid = useId();
const fileId = `media-file-${uid}`;
const altId = `media-alt-${uid}`;
const decorativeId = `media-decorative-${uid}`;
const helpId = `media-help-${uid}`;
const altErrorId = `media-alt-error-${uid}`;
const fileErrorId = `media-file-error-${uid}`;

const label = computed(
    () => props.label ?? COLLECTION_LABELS[props.collection] ?? 'Media',
);

const acceptsPdf = computed(() => PDF_COLLECTIONS.includes(props.collection));

/* §9.6: `cover` and `inline` must carry an `alt` or the decorative flag. */
const requiresAlt = computed(
    () => props.collection === 'cover' || props.collection === 'inline',
);

const form = useForm<{
    mediable_type: string;
    mediable_id: string;
    collection: string;
    file: File | null;
    alt: string;
    decorative: boolean;
}>({
    mediable_type: props.mediableType,
    mediable_id: String(props.mediableId),
    collection: props.collection,
    file: null,
    alt: props.media?.alt ?? '',
    decorative: props.media?.alt === '',
});

/*
 * The checkbox is the only thing that stores `''`; a blank field left untouched
 * is dropped from the payload altogether, which the request reads as "no
 * authored alt recorded" — the `NULL` state §9.2 permits for `logo`, `badge`
 * and `avatar` and refuses for `cover` and `inline`.
 */
form.transform((data) => {
    const payload: Record<string, unknown> = {
        mediable_type: data.mediable_type,
        mediable_id: data.mediable_id,
        collection: data.collection,
        file: data.file,
        decorative: data.decorative,
    };

    if (data.decorative) {
        payload.alt = '';
    } else if (data.alt.trim() !== '') {
        payload.alt = data.alt.trim();
    }

    return payload;
});

const chooseFile = (event: Event): void => {
    const input = event.target as HTMLInputElement;

    form.file = input.files?.[0] ?? null;
};

const submit = (): void => {
    form.post(STORE_URL, { forceFormData: true, preserveScroll: true });
};

const busy = ref(false);

const regenerate = (): void => {
    if (props.media === null) {
        return;
    }

    busy.value = true;

    router.post(
        regenerateUrl(props.media.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
            },
        },
    );
};

/* §5.5 lists this among the password-confirmed deletes; Inertia handles the prompt. */
const remove = (): void => {
    if (props.media === null) {
        return;
    }

    busy.value = true;

    router.delete(destroyUrl(props.media.id), {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
        },
    });
};

const describedBy = computed(() => {
    const ids: string[] = [];

    if (form.errors.alt) {
        ids.push(altErrorId);
    }

    ids.push(helpId);

    return ids.join(' ');
});

const status = computed(() => props.media?.status ?? null);
</script>

<template>
    <section class="space-y-4" :aria-labelledby="`${uid}-heading`">
        <h3 :id="`${uid}-heading`" class="font-display text-lg font-bold">
            {{ label }}
        </h3>

        <div v-if="media" class="flex flex-wrap items-start gap-4">
            <!--
                `media.alt` is handed over untouched, `null` included: the
                renderer owns §7.8's fallback (§9.2).
            -->
            <ResponsiveImage
                v-if="media.variants"
                :media="media.variants"
                :alt="media.alt"
                sizes="12rem"
                class="w-48"
            />

            <div class="space-y-2">
                <p class="text-meta text-muted-foreground">
                    Current file
                    <template v-if="status">
                        — status <code>{{ status }}</code></template
                    >
                    .
                </p>

                <p v-if="media.alt === ''" class="text-meta">
                    Marked decorative: rendered as <code>alt=""</code>.
                </p>
                <p v-else-if="media.alt" class="text-meta">
                    Alt text: “{{ media.alt }}”
                </p>
                <p v-else class="text-meta text-muted-foreground">
                    No authored alt text recorded.
                </p>

                <div class="flex flex-wrap gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="busy"
                        @click="regenerate"
                    >
                        <Spinner v-if="busy" />
                        Regenerate variants
                    </Button>

                    <Button
                        type="button"
                        variant="destructive"
                        size="sm"
                        :disabled="busy"
                        @click="remove"
                    >
                        Remove
                    </Button>
                </div>
            </div>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label :for="fileId">
                    {{ media ? 'Replace with a new file' : 'File' }}
                    <span aria-hidden="true">*</span>
                    <span class="sr-only">(required)</span>
                </Label>

                <!--
                    A native file input, not `ui/input`: that component binds
                    `v-model` to the element, and a file input's value cannot be
                    written back — a re-render would clear the chosen file.
                -->
                <input
                    :id="fileId"
                    type="file"
                    required
                    :accept="acceptsPdf ? 'application/pdf' : 'image/*'"
                    :aria-invalid="Boolean(form.errors.file) || undefined"
                    :aria-describedby="
                        form.errors.file ? fileErrorId : undefined
                    "
                    class="block w-full cursor-pointer rounded-sm border-2 border-ink bg-card p-2 text-sm text-foreground file:mr-3 file:cursor-pointer file:rounded-sm file:border-2 file:border-ink file:bg-secondary file:px-3 file:py-1.5 file:text-sm file:font-semibold aria-invalid:border-destructive"
                    @change="chooseFile"
                />

                <InputError :id="fileErrorId" :message="form.errors.file" />
            </div>

            <div class="grid gap-2">
                <Label :for="altId">
                    Alternative text
                    <span
                        v-if="requiresAlt && !form.decorative"
                        aria-hidden="true"
                        >*</span
                    >
                    <span v-if="requiresAlt && !form.decorative" class="sr-only"
                        >(required)</span
                    >
                </Label>

                <input
                    :id="altId"
                    v-model="form.alt"
                    type="text"
                    maxlength="255"
                    :disabled="form.decorative"
                    :required="requiresAlt && !form.decorative"
                    :aria-invalid="Boolean(form.errors.alt) || undefined"
                    :aria-describedby="describedBy"
                    class="h-9 w-full min-w-0 rounded-sm border-2 border-ink bg-card px-3 py-1 text-base outline-hidden transition-colors focus-visible:border-ring disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive md:text-sm"
                />

                <InputError :id="altErrorId" :message="form.errors.alt" />

                <p :id="helpId" class="text-meta text-muted-foreground">
                    Describe what the image shows, in the same language as the
                    page. Leave it blank only when the name it would repeat is
                    already next to it.
                </p>
            </div>

            <div class="grid gap-2">
                <Label :for="decorativeId" class="font-normal">
                    <Checkbox
                        :id="decorativeId"
                        :model-value="form.decorative"
                        @update:model-value="
                            (value) => (form.decorative = value === true)
                        "
                    />
                    <span>Decorative (no alt text)</span>
                </Label>

                <p class="text-meta text-muted-foreground">
                    Stores an empty <code>alt</code> — a recorded decision, not
                    a missing value — and the image is skipped by assistive
                    technology.
                </p>
            </div>

            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                {{ media ? 'Replace file' : 'Upload' }}
            </Button>
        </form>
    </section>
</template>
