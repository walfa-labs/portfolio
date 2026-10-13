<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import ContactController from '@/actions/App/Http/Controllers/Public/ContactController';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SeoHead from '@/components/public/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    title: string;
    description: string;
    canonical: string;
    jsonLd: Record<string, unknown>[];
    noindex: boolean;
};

defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: '/' },
            { title: 'Contact', href: '/contact' },
        ],
    },
});

/*
 * The triage set §8.8 fixes. The same five values are validated server-side by
 * `ContactMessageRequest`, so a request can be routed without a follow-up email
 * asking what it meant.
 */
const requestTypes = [
    { value: 'access', label: 'A copy of the data you hold about me' },
    { value: 'rectification', label: 'Correct something that is inaccurate' },
    { value: 'erasure', label: 'Delete data you hold about me' },
    { value: 'objection', label: 'Object to how my data is processed' },
    { value: 'other', label: 'Something else' },
];

/*
 * `_ts` is Unix seconds as an integer string, set when the form is rendered. The
 * server rejects anything younger than two seconds, which is the cheapest way to
 * drop automated submissions without a CAPTCHA (§3.11).
 */
const timestamp = Math.floor(Date.now() / 1000).toString();

const textareaClasses =
    'min-h-[10rem] w-full rounded-sm border-2 border-ink bg-card px-3 py-2 text-base outline-hidden transition focus-visible:border-ring aria-invalid:border-destructive md:text-sm';

const selectClasses =
    'h-9 w-full rounded-sm border-2 border-ink bg-card px-3 text-base outline-hidden focus-visible:border-ring md:text-sm';
</script>

<template>
    <SeoHead
        :title="title"
        :description="description"
        :canonical="canonical"
        :json-ld="jsonLd"
        :noindex="noindex"
    />

    <div class="mx-auto max-w-[65ch] space-y-8">
        <header class="space-y-3">
            <Heading as="h1" variant="display" title="Contact" />

            <p class="text-lead text-muted-foreground">
                Questions about the work, an opportunity, or a request about
                your data — all of it arrives in the same place.
            </p>

            <p class="text-meta text-muted-foreground">
                Your message is stored so it can be answered and deleted
                automatically after 24 months. It is never used for analytics
                and never sent anywhere else — see the
                <Link
                    href="/privacy"
                    class="underline decoration-2 underline-offset-4 hover:text-primary"
                    >privacy policy</Link
                >.
            </p>
        </header>

        <!--
            §8.4 item 4 — the contact form is explicitly masked. Clarity masks
            input and dropdown content in every mode, but the surrounding labels,
            hints and error text are the visitor's own words by association, and
            the message body must never reach a recording.
        -->
        <Form
            v-bind="ContactController.store.form()"
            v-slot="{ errors, processing }"
            class="space-y-6"
            data-clarity-mask="true"
        >
            <FormErrorSummary
                :errors="errors"
                :labels="{
                    name: 'Name',
                    email: 'Email address',
                    message: 'Message',
                    request_type: 'Reason for contact',
                }"
            />

            <p class="text-meta text-muted-foreground">
                Fields marked with an asterisk (*) are required.
            </p>

            <div class="grid gap-2">
                <Label for="name">
                    <span>
                        Name
                        <span aria-hidden="true">*</span>
                        <span class="sr-only">(required)</span>
                    </span>
                </Label>
                <Input
                    id="name"
                    name="name"
                    required
                    maxlength="120"
                    autocomplete="name"
                    placeholder="Your name"
                    :aria-invalid="Boolean(errors.name)"
                    :aria-describedby="errors.name ? 'name-error' : undefined"
                />
                <InputError id="name-error" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">
                    <span>
                        Email address
                        <span aria-hidden="true">*</span>
                        <span class="sr-only">(required)</span>
                    </span>
                </Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    maxlength="254"
                    autocomplete="email"
                    placeholder="you@example.com"
                    :aria-invalid="Boolean(errors.email)"
                    :aria-describedby="errors.email ? 'email-error' : undefined"
                />
                <InputError id="email-error" :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="request_type">
                    <span>
                        Reason for contact
                        <span aria-hidden="true">*</span>
                        <span class="sr-only">(required)</span>
                    </span>
                </Label>
                <select
                    id="request_type"
                    name="request_type"
                    required
                    :class="selectClasses"
                    :aria-invalid="Boolean(errors.request_type)"
                    :aria-describedby="
                        errors.request_type ? 'request_type-error' : undefined
                    "
                >
                    <option value="" disabled selected>Choose one…</option>
                    <option
                        v-for="type in requestTypes"
                        :key="type.value"
                        :value="type.value"
                    >
                        {{ type.label }}
                    </option>
                </select>
                <InputError
                    id="request_type-error"
                    :message="errors.request_type"
                />
            </div>

            <div class="grid gap-2">
                <Label for="message">
                    <span>
                        Message
                        <span aria-hidden="true">*</span>
                        <span class="sr-only">(required)</span>
                    </span>
                </Label>
                <textarea
                    id="message"
                    name="message"
                    required
                    minlength="10"
                    maxlength="5000"
                    rows="8"
                    :class="textareaClasses"
                    :aria-invalid="Boolean(errors.message)"
                    :aria-describedby="
                        errors.message
                            ? 'message-hint message-error'
                            : 'message-hint'
                    "
                />
                <p id="message-hint" class="text-meta text-muted-foreground">
                    Between 10 and 5000 characters.
                </p>
                <InputError id="message-error" :message="errors.message" />
            </div>

            <!--
                The honeypot (§3.11): hidden from people, irresistible to bots.
                `tabindex="-1"` keeps it out of the tab order and `aria-hidden`
                keeps it out of the accessibility tree, so nobody using a screen
                reader is asked to fill in a field that must stay empty.
            -->
            <div class="sr-only" aria-hidden="true">
                <Label for="website">Website</Label>
                <Input
                    id="website"
                    name="website"
                    tabindex="-1"
                    autocomplete="off"
                />
            </div>

            <input type="hidden" name="_ts" :value="timestamp" />

            <Button type="submit" size="lg" :disabled="processing">
                <Spinner v-if="processing" />
                Send message
            </Button>
        </Form>
    </div>
</template>
