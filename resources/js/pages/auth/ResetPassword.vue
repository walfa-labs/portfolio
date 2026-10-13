<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Reset password',
        description: 'Please enter your new password below',
    },
});

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
}>();

const inputEmail = ref(props.email);

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
    <Head title="Reset password" />

    <Form
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token, email })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-6">
            <FormErrorSummary
                :errors="errors"
                :labels="{
                    email: 'Email address',
                    password: 'Password',
                    password_confirmation: 'Confirm password',
                }"
            />

            <p class="text-meta text-muted-foreground">
                Fields marked with an asterisk (*) are required.
            </p>

            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    v-model="inputEmail"
                    class="mt-1 block w-full"
                    readonly
                    :aria-invalid="Boolean(errors.email)"
                    :aria-describedby="errors.email ? 'email-error' : undefined"
                />
                <InputError id="email-error" :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">
                    <span>
                        Password
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
                    required
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    autofocus
                    placeholder="Password"
                    :passwordrules="passwordRules"
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
                    required
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    placeholder="Confirm password"
                    :passwordrules="passwordRules"
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

            <Button
                type="submit"
                class="mt-4 w-full"
                :disabled="processing"
                data-test="reset-password-button"
            >
                <Spinner v-if="processing" />
                Reset password
            </Button>
        </div>
    </Form>
</template>
