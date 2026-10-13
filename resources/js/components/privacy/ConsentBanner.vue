<script setup lang="ts">
import { computed, onMounted, ref, useId } from 'vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useConsent } from '@/composables/useConsent';

/*
 * §8.3 — a non-intrusive region, not a modal: no scrim, no focus trap, no
 * `role="dialog"` and no `overflow: hidden` on `<body>`. Escape deliberately
 * does nothing, because dismissing into "undecided" is the ambiguous state the
 * section forbids. `position: fixed` is what keeps the contribution to CLS at
 * zero, and every value it renders is resolved synchronously in `setup()` so a
 * DNT/GPC visitor never sees a flash.
 */
const { analytics, advertising, isOpen, acceptAll, rejectAll, save } =
    useConsent();

/*
 * The banner is created on the client and never server-rendered. The server
 * cannot read `navigator.doNotTrack`, so a server-side decision about whether
 * to show it is a guess the client is free to disagree with — which is exactly
 * the "Hydration completed but contains mismatches" this gate removes.
 * `mounted` is `false` on both sides, so the first client render reproduces the
 * server's HTML byte for byte and the banner is added immediately afterwards
 * from the real signals, whole or not at all.
 */
const mounted = ref(false);

onMounted(() => {
    mounted.value = true;
});

const analyticsId = useId();
const analyticsHelpId = useId();
const advertisingId = useId();
const advertisingHelpId = useId();

/*
 * §8.3 asks for `aria-live="polite"` on a status line. The visible copy is
 * pinned, so the line is screen-reader-only and repeats the two category labels
 * verbatim rather than inventing a second voice.
 */
const statusMessage = computed(() => {
    const enabled = [
        analytics.value ? 'Analytics (Microsoft Clarity)' : null,
        advertising.value ? 'Advertising (Google Ads on blog posts)' : null,
    ].filter((label): label is string => label !== null);

    return enabled.length > 0
        ? `Selected: ${enabled.join(', ')}`
        : 'Selected: none';
});

function setAnalytics(value: boolean | 'indeterminate'): void {
    analytics.value = value === true;
}

function setAdvertising(value: boolean | 'indeterminate'): void {
    advertising.value = value === true;
}

function saveChoices(): void {
    save({ analytics: analytics.value, advertising: advertising.value });
}
</script>

<template>
    <!--
        §8.3 over §6.6: body-size text (not `text-meta`), a full-width bottom
        sheet below `md` and a bottom-left card at `md` and up, and no rotation.
        The overlay step comes from the `z-overlay` token, never a raw `z-40`.
    -->
    <div
        v-if="mounted && isOpen"
        role="region"
        aria-label="Cookie choices"
        class="fixed right-0 bottom-0 left-0 z-overlay rounded-t-sm border-2 border-ink bg-card p-4 text-base shadow-hard-lg md:right-auto md:bottom-4 md:left-4 md:max-w-md md:rounded-sm"
    >
        <p class="sr-only" aria-live="polite">{{ statusMessage }}</p>

        <h2 class="font-display text-xl font-bold tracking-tight">
            Cookies and analytics
        </h2>

        <p class="mt-2 text-muted-foreground">
            This site uses Microsoft Clarity for analytics and, on blog posts
            only, Google Ads. Nothing non-essential loads until you choose.
        </p>

        <div class="mt-4 space-y-4">
            <div class="flex items-start gap-3">
                <Checkbox
                    :id="analyticsId"
                    :model-value="analytics"
                    :aria-describedby="analyticsHelpId"
                    class="mt-0.5"
                    @update:model-value="setAnalytics"
                />
                <div class="min-w-0">
                    <Label
                        :for="analyticsId"
                        class="block text-base leading-snug"
                    >
                        Analytics (Microsoft Clarity)
                    </Label>
                    <p
                        :id="analyticsHelpId"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        Clarity records anonymised sessions so I can see how the
                        site is used.
                    </p>
                </div>
            </div>

            <div class="flex items-start gap-3">
                <Checkbox
                    :id="advertisingId"
                    :model-value="advertising"
                    :aria-describedby="advertisingHelpId"
                    class="mt-0.5"
                    @update:model-value="setAdvertising"
                />
                <div class="min-w-0">
                    <Label
                        :for="advertisingId"
                        class="block text-base leading-snug"
                    >
                        Advertising (Google Ads on blog posts)
                    </Label>
                    <p
                        :id="advertisingHelpId"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        Google Ads appear only on blog posts and are always
                        non-personalised.
                    </p>
                </div>
            </div>
        </div>

        <p class="mt-4">
            <TextLink href="/privacy">Read the privacy page</TextLink>
        </p>

        <!--
            §8.3 — one variant, one size and one width for all three: no
            primary-versus-ghost asymmetry, and "Reject all" is exactly as easy
            to reach as "Accept all".
        -->
        <div class="mt-4 grid gap-2 sm:grid-cols-3">
            <Button
                type="button"
                variant="secondary"
                size="sm"
                class="w-full"
                @click="acceptAll"
            >
                Accept all
            </Button>
            <Button
                type="button"
                variant="secondary"
                size="sm"
                class="w-full"
                @click="rejectAll"
            >
                Reject all
            </Button>
            <Button
                type="button"
                variant="secondary"
                size="sm"
                class="w-full"
                @click="saveChoices"
            >
                Save choices
            </Button>
        </div>
    </div>
</template>
