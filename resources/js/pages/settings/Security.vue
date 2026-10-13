<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/security';
import type { Props as ManagePasskeysProps } from '@/components/ManagePasskeys.vue';
import ManagePasskeys from '@/components/ManagePasskeys.vue';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';

// oxfmt-ignore
type Props = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Security settings',
                href: edit(),
            },
        ],
    },
});

const passwordRulesText = computed((): string => {
    const requirements: string[] = [];
    const minLength = /minlength:\s*(\d+)/.exec(props.passwordRules)?.[1];

    if (minLength) {
        requirements.push(`at least ${minLength} characters`);
    }

    if (props.passwordRules.includes('required: lower')) {
        requirements.push('a lowercase letter');
    }

    if (props.passwordRules.includes('required: upper')) {
        requirements.push('an uppercase letter');
    }

    if (props.passwordRules.includes('required: digit')) {
        requirements.push('a number');
    }

    if (props.passwordRules.includes('required: special')) {
        requirements.push('a symbol');
    }

    return requirements.length > 0
        ? `Your new password must contain ${requirements.join(', ')}.`
        : 'Choose a strong password you do not use anywhere else.';
});

const passwordDescribedBy = (field: string, error?: string): string =>
    ['password-rules', error ? `${field}-error` : undefined]
        .filter(Boolean)
        .join(' ');
</script>

<template>
    <Head title="Security settings" />

    <div class="space-y-6">
        <Heading as="h1" variant="small" title="Security settings" />

        <Heading
            variant="small"
            title="Update password"
            description="Ensure your account is using a long, random password to stay secure"
        />

        <Form
            v-bind="SecurityController.update.form()"
            :options="{
                preserveScroll: true,
            }"
            reset-on-success
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <FormErrorSummary
                :errors="errors"
                :labels="{
                    current_password: 'Current password',
                    password: 'New password',
                    password_confirmation: 'Confirm password',
                }"
            />

            <p class="text-meta text-muted-foreground">
                Fields marked with an asterisk (*) are required.
            </p>

            <div class="grid gap-2">
                <Label for="current_password">
                    <span>
                        Current password
                        <span aria-hidden="true">*</span>
                        <span class="sr-only">(required)</span>
                    </span>
                </Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                    placeholder="Current password"
                    :aria-invalid="Boolean(errors.current_password)"
                    :aria-describedby="
                        errors.current_password
                            ? 'current-password-error'
                            : undefined
                    "
                />
                <InputError
                    id="current-password-error"
                    :message="errors.current_password"
                />
            </div>

            <div class="grid gap-2">
                <Label for="password">
                    <span>
                        New password
                        <span aria-hidden="true">*</span>
                        <span class="sr-only">(required)</span>
                    </span>
                </Label>
                <p id="password-rules" class="text-meta text-muted-foreground">
                    {{ passwordRulesText }}
                </p>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="new-password"
                    placeholder="New password"
                    :passwordrules="props.passwordRules"
                    :aria-invalid="Boolean(errors.password)"
                    :aria-describedby="
                        passwordDescribedBy('password', errors.password)
                    "
                />
                <InputError id="password-error" :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">
                    <span>
                        Confirm password
                        <span aria-hidden="true">*</span>
                        <span class="sr-only">(required)</span>
                    </span>
                </Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    required
                    autocomplete="new-password"
                    placeholder="Confirm password"
                    :passwordrules="props.passwordRules"
                    :aria-invalid="Boolean(errors.password_confirmation)"
                    :aria-describedby="
                        passwordDescribedBy(
                            'password_confirmation',
                            errors.password_confirmation,
                        )
                    "
                />
                <InputError
                    id="password_confirmation-error"
                    :message="errors.password_confirmation"
                />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-password-button"
                >
                    Save
                </Button>
            </div>
        </Form>
    </div>

    <ManageTwoFactor
        :canManageTwoFactor="canManageTwoFactor"
        :requiresConfirmation="requiresConfirmation"
        :twoFactorEnabled="twoFactorEnabled"
    />

    <ManagePasskeys
        :canManagePasskeys="canManagePasskeys"
        :passkeys="passkeys"
    />
</template>
