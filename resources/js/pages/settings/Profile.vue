<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Profile settings" />

    <div class="flex flex-col space-y-6">
        <Heading
            as="h1"
            variant="small"
            title="Profile"
            description="Update your name and email address"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <FormErrorSummary
                :errors="errors"
                :labels="{ name: 'Name', email: 'Email address' }"
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
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                    :aria-invalid="Boolean(errors.name)"
                    :aria-describedby="errors.name ? 'name-error' : undefined"
                />
                <InputError
                    id="name-error"
                    class="mt-2"
                    :message="errors.name"
                />
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
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                    :aria-invalid="Boolean(errors.email)"
                    :aria-describedby="errors.email ? 'email-error' : undefined"
                />
                <InputError
                    id="email-error"
                    class="mt-2"
                    :message="errors.email"
                />
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    Your email address is unverified.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-primary/40 decoration-2 underline-offset-4 transition-colors hover:decoration-primary"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </p>

                <p
                    v-if="page.props.status === 'verification-link-sent'"
                    role="status"
                    class="mt-2 text-sm font-medium text-success"
                >
                    A new verification link has been sent to your email address.
                </p>
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
