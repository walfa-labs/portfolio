<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { clientAdvertisingAllowed } from '@/composables/useConsent';

/*
 * The `privacy` prop (§8.9 item 2, §15.28) is the only route by which a vendor
 * id reaches the browser — never a `VITE_*` variable, so the kill switches work
 * without a rebuild.
 */
type PrivacyProps = {
    adsAllowed?: boolean;
    adsenseClient?: string | null;
    adsenseSlot?: string | null;
};

/*
 * `adsbygoogle` is an array with properties hanging off it; the vendor's own
 * pattern is `(adsbygoogle = window.adsbygoogle || []).pauseAdRequests = 1`.
 */
type AdsByGoogleQueue = {
    requestNonPersonalizedAds?: number;
    pauseAdRequests?: number;
    push: (command: Record<string, never>) => void;
};

const LOADER_ATTRIBUTE = 'data-adsense-loader';

const page = usePage<{ privacy?: PrivacyProps }>();

const slot = ref<HTMLElement | null>(null);

let unit: HTMLElement | null = null;
let injected = false;

const client = computed(() => page.props.privacy?.adsenseClient ?? '');
const slotId = computed(() => page.props.privacy?.adsenseSlot ?? '');

/*
 * The wrapper is rendered only when there is an ad to put in it: an article page
 * visited without advertising consent must not show a label and an empty 250px
 * box. When it is rendered it is part of the first paint, which is what reserves
 * the space before the loader arrives — that, and the fixed-size unit, is the
 * whole CLS defence (§8.5 item 4).
 *
 * This is the *server's* answer, and it stays the markup's only gate so SSR and
 * hydration agree. It is not permission to load Google: `onMounted` re-reads the
 * browser's own signals before anything is injected.
 */
const showSlot = computed(
    () =>
        page.props.privacy?.adsAllowed === true &&
        client.value !== '' &&
        slotId.value !== '',
);

function adQueue(): AdsByGoogleQueue {
    const holder = window as unknown as { adsbygoogle?: AdsByGoogleQueue };
    holder.adsbygoogle =
        holder.adsbygoogle ?? ([] as unknown as AdsByGoogleQueue);

    return holder.adsbygoogle;
}

onMounted(() => {
    const host = slot.value;

    if (!showSlot.value || host === null) {
        return;
    }

    /*
     * §8.5 item 3 — the belt to the server prop's braces. `showSlot` is the
     * server's answer, and a proxy, extension, stale document or shared cache can
     * make it the wrong one; a wrong server decision must never become a Google
     * request. So the client signals are re-read here — `navigator.doNotTrack` in
     * the pinned opt-out set, `navigator.globalPrivacyControl`, and a stored
     * record that still grants `advertising` at the live policy version — and
     * nothing is injected unless they agree too. The visible box and label stay
     * driven by `showSlot` alone, so this check cannot change what renders.
     */
    if (!clientAdvertisingAllowed()) {
        return;
    }

    const ads = adQueue();

    /*
     * §8.5 item 3 — non-personalised before any push, so every request carries
     * `&npa=1`. Personalised ads would need a Google-certified CMP integrated
     * with IAB TCF; this site ships a custom banner, so they are unavailable
     * here by construction, not by accident.
     */
    ads.requestNonPersonalizedAds = 1;

    /*
     * The loader is never written into the blade `<head>`: Inertia does not
     * re-run `<head>`, so a head-level script would load Google on every route.
     * It is injected here, tagged, and removed on unmount — this component only
     * exists on `writing.show`, so no other route can contact Google at all.
     */
    if (document.querySelector(`script[${LOADER_ATTRIBUTE}]`) === null) {
        const loader = document.createElement('script');
        loader.async = true;
        loader.crossOrigin = 'anonymous';
        loader.src = `https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=${encodeURIComponent(client.value)}`;
        loader.setAttribute(LOADER_ATTRIBUTE, '');
        document.head.appendChild(loader);

        injected = true;
    }

    /*
     * The unit is deliberately fixed-size: neither the auto-format nor the
     * full-width responsive attribute is set, because either one lets the unit
     * resize itself after load — the layout shift the fixed `min-h-*` on the
     * wrapper exists to prevent. The node carries no name, no email and no
     * identifier: never pass PII to Google.
     */
    const ins = document.createElement('ins');
    ins.className = 'adsbygoogle';
    ins.style.display = 'block';
    ins.setAttribute('data-ad-client', client.value);
    ins.setAttribute('data-ad-slot', slotId.value);
    host.appendChild(ins);

    unit = ins;

    ads.push({});
});

/*
 * Inertia unmounts this component on any navigation away, including back to the
 * blog list, so the teardown is complete: no ad request can be made, no `<ins>`
 * is left behind and the loader script is gone.
 */
onBeforeUnmount(() => {
    if (!injected && unit === null) {
        return;
    }

    adQueue().pauseAdRequests = 1;

    unit?.remove();
    unit = null;

    document.querySelector(`script[${LOADER_ATTRIBUTE}]`)?.remove();
    injected = false;
});
</script>

<template>
    <!--
        A named `region`, so a screen-reader user is told what this is and can
        skip it, without a second copy of the visible label. `role="region"`
        rather than `role="complementary"`: an ad is not related content.
    -->
    <div
        v-if="showSlot"
        role="region"
        aria-labelledby="advertisement-label"
        class="space-y-2"
    >
        <p
            id="advertisement-label"
            class="text-xs tracking-wide text-muted-foreground uppercase"
        >
            Advertisement
        </p>

        <!--
            The reservation: `min-h-[250px] md:min-h-[90px]` matches the unit at
            each breakpoint, and `overflow-hidden` keeps a mis-sized creative
            from pushing the page around. `bg-muted` means the space reads as
            reserved rather than broken before the ad paints.
        -->
        <div
            ref="slot"
            class="min-h-[250px] overflow-hidden rounded-md border-2 border-ink bg-muted md:min-h-[90px]"
        />
    </div>
</template>
