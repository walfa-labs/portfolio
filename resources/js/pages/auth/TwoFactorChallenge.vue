<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { computed, nextTick, ref, useTemplateRef, watchEffect } from 'vue';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';
import { store } from '@/routes/two-factor/login';
import type { TwoFactorConfigContent } from '@/types';

const showRecoveryInput = ref<boolean>(false);
const code = ref<string>('');
const announcement = ref<string>('');

const otpPanel = useTemplateRef<HTMLElement>('otpPanel');
const recoveryInput =
    useTemplateRef<InstanceType<typeof Input>>('recoveryInput');

const authConfigContent = computed<TwoFactorConfigContent>(() => {
    if (showRecoveryInput.value) {
        return {
            title: 'Recovery code',
            description:
                'Please confirm access to your account by entering one of your emergency recovery codes.',
            buttonText: 'login using an authentication code',
        };
    }

    return {
        title: 'Authentication code',
        description:
            'Enter the authentication code provided by your authenticator application.',
        buttonText: 'login using a recovery code',
    };
});

watchEffect(() => {
    setLayoutProps({
        title: authConfigContent.value.title,
        description: authConfigContent.value.description,
    });
});

const focusFirstField = async (): Promise<void> => {
    await nextTick();

    if (showRecoveryInput.value) {
        recoveryInput.value?.$el?.focus();

        return;
    }

    otpPanel.value?.querySelector<HTMLInputElement>('input')?.focus();
};

const toggleRecoveryMode = (clearErrors: () => void): void => {
    showRecoveryInput.value = !showRecoveryInput.value;
    clearErrors();
    code.value = '';

    announcement.value = showRecoveryInput.value
        ? 'Recovery code field shown.'
        : 'Authentication code field shown.';

    void focusFirstField();
};
</script>

<template>
    <Head title="Two-factor authentication" />

    <div class="space-y-6">
        <p role="status" class="sr-only">{{ announcement }}</p>

        <template v-if="!showRecoveryInput">
            <Form
                v-bind="store.form()"
                class="space-y-4"
                @error="code = ''"
                #default="{ errors, processing, clearErrors }"
            >
                <FormErrorSummary
                    :errors="errors"
                    :ids="{ code: 'otp' }"
                    :labels="{ code: 'Authentication code' }"
                />

                <p class="text-meta text-muted-foreground">
                    Fields marked with an asterisk (*) are required.
                </p>

                <input type="hidden" name="code" :value="code" />
                <div
                    class="flex flex-col items-center justify-center space-y-3 text-center"
                >
                    <Label for="otp">
                        <span>
                            Authentication code
                            <span aria-hidden="true">*</span>
                            <span class="sr-only">(required)</span>
                        </span>
                    </Label>
                    <div
                        ref="otpPanel"
                        class="flex w-full items-center justify-center"
                    >
                        <InputOTP
                            id="otp"
                            v-model="code"
                            :maxlength="6"
                            :disabled="processing"
                            autofocus
                            required
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            :aria-invalid="Boolean(errors.code)"
                            :aria-describedby="
                                errors.code ? 'otp-error' : undefined
                            "
                        >
                            <InputOTPGroup>
                                <InputOTPSlot
                                    v-for="index in 6"
                                    :key="index"
                                    :index="index - 1"
                                />
                            </InputOTPGroup>
                        </InputOTP>
                    </div>
                    <InputError id="otp-error" :message="errors.code" />
                </div>
                <Button type="submit" class="w-full" :disabled="processing"
                    >Continue</Button
                >
                <div class="text-center text-sm text-muted-foreground">
                    <span>or you can </span>
                    <button
                        type="button"
                        :aria-expanded="showRecoveryInput"
                        class="text-foreground underline decoration-primary/40 decoration-2 underline-offset-4 transition-colors hover:decoration-primary"
                        @click="() => toggleRecoveryMode(clearErrors)"
                    >
                        {{ authConfigContent.buttonText }}
                    </button>
                </div>
            </Form>
        </template>

        <template v-else>
            <Form
                v-bind="store.form()"
                class="space-y-4"
                #default="{ errors, processing, clearErrors }"
            >
                <FormErrorSummary
                    :errors="errors"
                    :labels="{ recovery_code: 'Recovery code' }"
                />

                <p class="text-meta text-muted-foreground">
                    Fields marked with an asterisk (*) are required.
                </p>

                <div class="grid gap-2">
                    <Label for="recovery_code">
                        <span>
                            Recovery code
                            <span aria-hidden="true">*</span>
                            <span class="sr-only">(required)</span>
                        </span>
                    </Label>
                    <Input
                        id="recovery_code"
                        ref="recoveryInput"
                        name="recovery_code"
                        type="text"
                        placeholder="Enter recovery code"
                        v-focus
                        required
                        :aria-invalid="Boolean(errors.recovery_code)"
                        :aria-describedby="
                            errors.recovery_code
                                ? 'recovery-code-error'
                                : undefined
                        "
                    />
                    <InputError
                        id="recovery-code-error"
                        :message="errors.recovery_code"
                    />
                </div>

                <Button type="submit" class="w-full" :disabled="processing"
                    >Continue</Button
                >

                <div class="text-center text-sm text-muted-foreground">
                    <span>or you can </span>
                    <button
                        type="button"
                        :aria-expanded="showRecoveryInput"
                        class="text-foreground underline decoration-primary/40 decoration-2 underline-offset-4 transition-colors hover:decoration-primary"
                        @click="() => toggleRecoveryMode(clearErrors)"
                    >
                        {{ authConfigContent.buttonText }}
                    </button>
                </div>
            </Form>
        </template>
    </div>
</template>
