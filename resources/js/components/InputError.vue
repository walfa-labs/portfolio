<script setup lang="ts">
import { computed, useId } from 'vue';

type Props = {
    message?: string;
    id?: string;
};

const props = defineProps<Props>();

const fallbackId = `input-error-${useId()}`;
const errorId = computed(() => props.id ?? fallbackId);
</script>

<template>
    <p v-if="message" :id="errorId" class="text-sm text-destructive">
        {{ message }}
    </p>
</template>
