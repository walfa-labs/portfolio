<script setup lang="ts">
import { useId } from 'vue';

const patternId = `pattern-${useId()}`;
</script>

<template>
    <svg
        class="absolute inset-0 size-full text-ink"
        fill="none"
        aria-hidden="true"
    >
        <defs>
            <pattern
                :id="patternId"
                x="0"
                y="0"
                width="6"
                height="6"
                patternUnits="userSpaceOnUse"
            >
                <path
                    d="M-1 5L5 -1"
                    stroke="currentColor"
                    stroke-width="1"
                    stroke-opacity="0.12"
                />
            </pattern>
        </defs>
        <rect
            stroke="none"
            :fill="`url(#${patternId})`"
            width="100%"
            height="100%"
        />
    </svg>
</template>
