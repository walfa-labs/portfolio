<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 32 32"
        :class="className"
        aria-hidden="true"
        v-bind="$attrs"
    >
        <rect
            x="1"
            y="1"
            width="30"
            height="30"
            rx="2"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
        />
        <text
            x="16"
            y="16"
            class="font-display"
            fill="currentColor"
            font-size="13"
            font-weight="800"
            text-anchor="middle"
            dominant-baseline="central"
        >
            WA
        </text>
    </svg>
</template>
