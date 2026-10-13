<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, useTemplateRef, watch } from 'vue';
import AlertError from '@/components/AlertError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

/*
 * §5.9 — a delete is never a bare `DELETE`. The dialog names the record, is
 * focus-trapped and `Escape`-closable through `ui/dialog` (both Reka-provided;
 * re-implementing them is the defect §7.6 warns about), and performs the
 * request itself against the `action` it is handed.
 *
 * `DialogTitle` is mandatory (§7.6): a dialog without one is an axe failure and
 * a hard CI failure, so it is always rendered from the `title` prop.
 *
 * Initial focus goes to Cancel, not Delete (§7.6). Reka moves focus to the
 * first focusable element by default, which would be the destructive button, so
 * `openAutoFocus` is cancelled and the least destructive action is focused by
 * hand.
 */
type Props = {
    open: boolean;
    title: string;
    description: string;
    action: string;
};

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    confirmed: [];
}>();

const cancel = useTemplateRef<InstanceType<typeof Button>>('cancel');

const processing = ref(false);
const errors = ref<string[]>([]);

const focusCancel = (event: Event): void => {
    event.preventDefault();
    cancel.value?.$el?.focus();
};

/*
 * The dialog performs the request: it is handed the `action` for exactly this
 * reason, and a page that also sent it would fire the delete twice. `confirmed`
 * is the page's signal that the admin committed — clearing a selection, or
 * closing its own state — not a request to re-send anything.
 */
const confirm = (): void => {
    processing.value = true;

    emit('confirmed');

    router.delete(props.action, {
        preserveScroll: true,
        onStart: () => {
            errors.value = [];
        },
        onSuccess: () => {
            /* The record is gone, so the dialog must not survive the visit even
               if the page's state is preserved. */
            emit('update:open', false);
        },
        onError: (validationErrors) => {
            errors.value = Object.values(validationErrors);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
};

watch(
    () => props.open,
    (open) => {
        if (open) {
            errors.value = [];
        }
    },
);
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent @open-auto-focus="focusCancel">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <AlertError v-if="errors.length > 0" :errors="errors" />

            <!--
                Cancel is a `DialogClose` and is never disabled: §7.6 requires
                that a submit disabled during `processing` cannot take the
                escape path with it.
            -->
            <DialogFooter>
                <DialogClose as-child>
                    <Button ref="cancel" type="button" variant="secondary">
                        Cancel
                    </Button>
                </DialogClose>

                <Button
                    type="button"
                    variant="destructive"
                    :disabled="processing"
                    @click="confirm"
                >
                    <Spinner v-if="processing" />
                    Delete
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
