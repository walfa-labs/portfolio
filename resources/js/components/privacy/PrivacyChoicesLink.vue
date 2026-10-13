<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useConsent } from '@/composables/useConsent';

/*
 * §8.3 — re-opening is the same banner and the same state machine; this is only
 * the control. It sits in `AppLayout`'s sidebar footer and on `/privacy`, and
 * reopens the banner with the stored values.
 *
 * Under DNT/GPC the banner can never render, so the control is withheld rather
 * than left as a button that does nothing.
 */
const { optOut, open } = useConsent();
</script>

<template>
    <Button
        v-if="!optOut"
        type="button"
        variant="outline"
        size="sm"
        @click="open"
    >
        Privacy choices
    </Button>
</template>
