<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { nextTick, onMounted, onUnmounted, ref } from 'vue';

const MAIN_CONTENT_ID = 'main-content';

/**
 * Upper bound on how long to wait for Inertia to swap `<title>`. The swap lands
 * a few milliseconds after `navigate` in a focused tab; the bound only matters
 * when the next page's title is identical to the current one.
 */
const TITLE_SETTLE_TIMEOUT_MS = 1500;

const message = ref('');

let unsubscribe: (() => void) | null = null;

function focusTarget(): HTMLElement | null {
    const main = document.getElementById(MAIN_CONTENT_ID);

    if (main) {
        return main;
    }

    const heading = document.querySelector<HTMLElement>('h1');

    if (heading && !heading.hasAttribute('tabindex')) {
        heading.setAttribute('tabindex', '-1');
    }

    return heading;
}

/**
 * `navigate` fires before Inertia's `Head` component swaps `<title>`, so reading
 * `document.title` after `nextTick()` announces the page the user just left.
 * Wait for the head to settle instead, with a bounded fallback so an unchanged
 * title still gets announced.
 */
function settledTitle(previousTitle: string): Promise<string> {
    if (document.title !== previousTitle) {
        return Promise.resolve(document.title);
    }

    return new Promise((resolve) => {
        const observer = new MutationObserver(() => {
            if (document.title !== previousTitle) {
                finish(document.title);
            }
        });

        const timer = window.setTimeout(() => {
            finish(document.title);
        }, TITLE_SETTLE_TIMEOUT_MS);

        function finish(title: string): void {
            observer.disconnect();
            window.clearTimeout(timer);
            resolve(title);
        }

        observer.observe(document.head, {
            childList: true,
            subtree: true,
            characterData: true,
        });
    });
}

async function handleNavigate(event: Event): Promise<void> {
    const detail = (
        event as CustomEvent<{ type: 'initial' | 'visit' | 'history' }>
    ).detail;

    if (detail?.type !== 'visit') {
        return;
    }

    const previousTitle = document.title;

    message.value = '';

    await nextTick();

    message.value = await settledTitle(previousTitle);

    focusTarget()?.focus();
}

onMounted(() => {
    unsubscribe = router.on('navigate', handleNavigate);
});

onUnmounted(() => {
    unsubscribe?.();
    unsubscribe = null;
});
</script>

<template>
    <span class="sr-only">{{ message }}</span>
</template>
