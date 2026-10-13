import { usePage } from '@inertiajs/vue3';
import type { ComputedRef, Ref } from 'vue';
import { computed, ref } from 'vue';

/*
 * §8.3 — the consent record lives in one client-written cookie and nowhere
 * else. It is treated as untrusted input everywhere: it never authorises
 * anything, and tampering only changes the tamperer's own tracking state.
 */
export const CONSENT_COOKIE_NAME = 'consent';
export const CONSENT_COOKIE_MAX_AGE = 15552000;
export const CONSENT_POLICY_VERSION = 1;

/*
 * §8.1 — the non-essential stores this module is allowed to clear: the two
 * preferences the site writes itself, plus the first-party Clarity cookies we
 * can reach. Clarity's third-party `MUID`/`CLID` live on Microsoft's domains
 * and are out of reach from JavaScript.
 */
const NON_ESSENTIAL_COOKIE_NAMES = [
    'appearance',
    'sidebar_state',
    '_clck',
    '_clsk',
] as const;

export type ConsentCategory = 'analytics' | 'advertising';
export type ConsentDecision = 'accept_all' | 'reject_all' | 'custom';

export type ConsentRecord = {
    v: number;
    ts: string;
    analytics: boolean;
    advertising: boolean;
    decision: ConsentDecision;
};

export type ConsentCategories = {
    analytics: boolean;
    advertising: boolean;
};

/*
 * The `privacy` Inertia prop shared by `App\Http\Middleware\ResolvePrivacyState`
 * (§8.9, §15.28). The three ids are pinned by the coordinator's decision 3;
 * `policyVersion` is optional so the client still detects a downgrade when the
 * server only reports a stale record as `consent: null`.
 */
export type PrivacyProp = {
    optOut: boolean;
    analyticsAllowed: boolean;
    adsAllowed: boolean;
    consent: ConsentRecord | null;
    cspNonce: string | null;
    clarityProjectId: string | null;
    adsenseClient: string | null;
    adsenseSlot: string | null;
    policyVersion?: number;
};

export type UseConsentReturn = {
    optOut: ComputedRef<boolean>;
    analyticsAllowed: ComputedRef<boolean>;
    adsAllowed: ComputedRef<boolean>;
    analytics: Ref<boolean>;
    advertising: Ref<boolean>;
    record: ComputedRef<ConsentRecord | null>;
    hasDecision: ComputedRef<boolean>;
    isOpen: ComputedRef<boolean>;
    open: () => void;
    save: (categories: ConsentCategories) => void;
    acceptAll: () => void;
    rejectAll: () => void;
    withdraw: () => void;
};

type NavigatorWithGlobalPrivacyControl = Navigator & {
    globalPrivacyControl?: boolean;
};

/*
 * §8.2, decision 10 — the header values that mean "object": `1` and `01` are
 * the forms the Tracking Preference Expression and the GPC spec use, `yes` and
 * `on` the legacy forms older user agents send. Kept in step with
 * `ResolvePrivacyState::OPT_OUT_VALUES` so the header the server reads and the
 * client signal the page reads are judged by one rule.
 */
const OPT_OUT_VALUES: readonly string[] = ['1', '01', 'yes', 'on'];

type ClarityQueue = ((...args: unknown[]) => void) & { q?: unknown[][] };

const page = usePage();

const privacy = computed<PrivacyProp | undefined>(
    () => page.props?.privacy as PrivacyProp | undefined,
);

/*
 * §8.2 — the client's own signal, read only where there is a client. On the
 * server there is no signal at all, and `typeof navigator` is not a usable test
 * for that: Node 24 ships a global `navigator` whose `doNotTrack` is
 * `undefined`, so the old guard never fired and every visitor was reported as
 * opted out during SSR. `typeof window` is the reliable server check, and the
 * pinned value set is what decides an opt-out — so an absent, `null` or
 * `undefined` `doNotTrack` means "no objection recorded", never "objection".
 */
function clientOptOutSignal(): boolean {
    if (typeof window === 'undefined' || typeof navigator === 'undefined') {
        return false;
    }

    const dnt =
        typeof navigator.doNotTrack === 'string'
            ? navigator.doNotTrack.trim().toLowerCase()
            : '';

    return (
        OPT_OUT_VALUES.includes(dnt) ||
        (navigator as NavigatorWithGlobalPrivacyControl)
            .globalPrivacyControl === true
    );
}

/*
 * §8.2 — the client is authoritative for gating: a proxy, extension or CDN can
 * strip a request header, but it cannot change what the page's own JavaScript
 * observes. The server's `optOut` only picks the SSR initial state, so the two
 * are unioned and the most protective signal wins. Neither signal ever grants
 * consent: `DNT: 0` and an absent `Sec-GPC` mean "no objection recorded".
 */
const optOut = computed<boolean>(
    () => privacy.value?.optOut === true || clientOptOutSignal(),
);

const recordState = ref<ConsentRecord | null>(null);
const analytics = ref(false);
const advertising = ref(false);
const openRequested = ref(false);

let initialized = false;

/*
 * On the server the shared prop is the only per-request signal, so the markup
 * stays deterministic and hydration matches — module state must not leak from
 * one SSR request into the next.
 */
const record = computed<ConsentRecord | null>(() => {
    if (typeof window === 'undefined') {
        return privacy.value?.consent ?? null;
    }

    return recordState.value;
});

const hasDecision = computed<boolean>(() => record.value !== null);

const isOpen = computed<boolean>(() => {
    if (typeof window === 'undefined') {
        return !optOut.value && (privacy.value?.consent ?? null) === null;
    }

    return openRequested.value && !optOut.value;
});

/*
 * §8.4 item 2, gate 4 — Clarity is never loaded on `/admin`, which shows real
 * personal data and has no analytics value. The server enforces it for the SSR
 * tag; the client enforces it here so a grant made while standing on `/admin`
 * cannot inject the loader either. `page.url` is reactive, so this follows an
 * Inertia visit as well as a full load.
 */
const onAdminRoute = computed<boolean>(() =>
    (page.url ?? '').startsWith('/admin'),
);

/*
 * The kill switch is not a prop of its own, but it is derivable from the ones
 * that are: with analytics consent granted, no opt-out and a non-admin route,
 * the server's `analyticsAllowed` can only still be false because
 * `ANALYTICS_ENABLED` is off. A fresh client grant leaves the server's `consent`
 * null, so the derivation stays false and the grant is honoured.
 */
const analyticsKillSwitchOff = computed<boolean>(
    () =>
        privacy.value?.consent?.analytics === true &&
        privacy.value.analyticsAllowed === false &&
        privacy.value.optOut === false &&
        !onAdminRoute.value,
);

/*
 * Gating follows the *saved* record, never the banner's pending checkboxes:
 * toggling a category does nothing until "Save choices" is pressed. These are
 * the consent-side signals; the route and kill-switch gates are applied where a
 * loader would actually be injected.
 */
const analyticsAllowed = computed<boolean>(
    () => !optOut.value && record.value?.analytics === true,
);

const adsAllowed = computed<boolean>(
    () => !optOut.value && record.value?.advertising === true,
);

/**
 * §8.1 — `appearance` and `sidebar_state` are non-essential preferences, so
 * under DNT/GPC they must not be written. The guard fails closed: the server
 * already defaults to `system` appearance and `sidebarOpen = true` when the
 * cookie is absent.
 */
export function storageAllowed(): boolean {
    return !optOut.value;
}

function secureSuffix(): string {
    // Pinned 19 — the cookie is written in the browser, so it cannot evaluate
    // `$request->secure()`; `location.protocol` is the only signal available.
    return typeof location !== 'undefined' && location.protocol === 'https:'
        ? '; Secure'
        : '';
}

function isConsentRecord(value: unknown): value is ConsentRecord {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const candidate = value as Record<string, unknown>;

    return (
        typeof candidate.v === 'number' &&
        typeof candidate.ts === 'string' &&
        typeof candidate.analytics === 'boolean' &&
        typeof candidate.advertising === 'boolean' &&
        (candidate.decision === 'accept_all' ||
            candidate.decision === 'reject_all' ||
            candidate.decision === 'custom')
    );
}

/**
 * The §8.3 cookie as the browser sees it. The value is percent-encoded JSON so
 * the `{`, `"` and `,` never reach the cookie header raw; PHP URL-decodes cookie
 * values, so `$request->cookie('consent')` is the JSON string again.
 */
export function readConsentCookie(): ConsentRecord | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const prefix = `${CONSENT_COOKIE_NAME}=`;
    const raw = document.cookie
        .split('; ')
        .find((part) => part.startsWith(prefix))
        ?.slice(prefix.length);

    if (!raw) {
        return null;
    }

    try {
        const parsed: unknown = JSON.parse(decodeURIComponent(raw));

        return isConsentRecord(parsed) ? parsed : null;
    } catch {
        return null;
    }
}

/**
 * §8.5 item 3 — the browser's own answer to "may this page load Google?".
 *
 * `WritingAdSlot.vue` gates its *markup* on the server's `privacy.adsAllowed`
 * prop so the reserved box is in the first paint and SSR matches hydration, but
 * the server can be wrong about the visitor: a proxy or extension can strip the
 * request header, and a stale or shared-cached document can carry a decision
 * resolved for someone else. Only the browser sees the visitor's own signals, so
 * the loader is injected only when this second, independent check agrees. It is
 * the same shape as the Clarity loader's re-check, and it fails closed: a missing
 * or unreadable record, a stale policy version or an absent `advertising` flag
 * all mean "no ad".
 *
 * The version is compared for equality rather than "at least", which is the rule
 * `ResolvePrivacyState::isCurrent` and the Clarity partial apply, so the server,
 * the cookie reader here and the ad slot cannot disagree about a live record.
 */
export function clientAdvertisingAllowed(): boolean {
    if (clientOptOutSignal()) {
        return false;
    }

    const stored = readConsentCookie();

    if (stored === null || stored.advertising !== true) {
        return false;
    }

    return stored.v === (privacy.value?.policyVersion ?? CONSENT_POLICY_VERSION);
}

export function writeConsentCookie(value: ConsentRecord): void {
    if (typeof document === 'undefined') {
        return;
    }

    const encoded = encodeURIComponent(JSON.stringify(value));

    document.cookie = `${CONSENT_COOKIE_NAME}=${encoded}; Path=/; Max-Age=${CONSENT_COOKIE_MAX_AGE}; SameSite=Lax${secureSuffix()}`;
}

export function clearConsentCookie(): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.cookie = `${CONSENT_COOKIE_NAME}=; Path=/; Max-Age=0; SameSite=Lax${secureSuffix()}`;
}

function clearNonEssentialStorage(): void {
    if (typeof document !== 'undefined') {
        for (const name of NON_ESSENTIAL_COOKIE_NAMES) {
            document.cookie = `${name}=; Path=/; Max-Age=0; SameSite=Lax${secureSuffix()}`;
        }
    }

    if (typeof localStorage !== 'undefined') {
        localStorage.removeItem('appearance');
    }
}

function clarityQueue(): ClarityQueue | undefined {
    if (typeof window === 'undefined') {
        return undefined;
    }

    const candidate = (window as Window & { clarity?: ClarityQueue }).clarity;

    return typeof candidate === 'function' ? candidate : undefined;
}

/*
 * §8.4 item 3 — an Inertia visit does not re-execute `<head>`, so a visitor who
 * accepts analytics after the first page would get no Clarity until a hard
 * reload. The loader is therefore injected client-side on grant, idempotently:
 * the check covers both the tag the server may already have emitted and one
 * this function emitted earlier.
 */
function grantAnalytics(): void {
    if (typeof document === 'undefined') {
        return;
    }

    /*
     * The two gates the consent record cannot express: the kill switch and the
     * `/admin` exclusion (§8.4 item 2). Without them a grant made on `/admin`
     * would inject Clarity over the owner's real personal data, and a deployment
     * that kept the project id while switching `ANALYTICS_ENABLED` off would
     * ignore its own kill switch.
     */
    if (onAdminRoute.value || analyticsKillSwitchOff.value) {
        return;
    }

    const projectId = privacy.value?.clarityProjectId;

    if (!projectId) {
        return;
    }

    const win = window as Window & { clarity?: ClarityQueue };
    const existing = clarityQueue();

    if (existing) {
        existing('consent', true);
    } else {
        const queue = ((...args: unknown[]) => {
            (queue.q = queue.q ?? []).push(args);
        }) as ClarityQueue;

        queue.q = [];
        win.clarity = queue;
        queue('consent', true);
    }

    const alreadyLoaded = document.querySelector(
        'script[data-clarity], script[src*="clarity.ms/tag/"]',
    );

    if (alreadyLoaded) {
        return;
    }

    const script = document.createElement('script');
    script.async = true;
    script.dataset.clarity = '';
    script.src = `https://www.clarity.ms/tag/${projectId}`;
    document.head.appendChild(script);
}

/*
 * §8.3 — withdrawal is immediate: Clarity is told to stop, and the first-party
 * cookies it wrote are expired. Its third-party cookies on `clarity.ms` and
 * `bing.com` are not reachable from here, and `/privacy` says so.
 */
function revokeAnalytics(): void {
    clarityQueue()?.('consent', false);

    if (typeof document === 'undefined') {
        return;
    }

    for (const name of ['_clck', '_clsk']) {
        document.cookie = `${name}=; Path=/; Max-Age=0; SameSite=Lax${secureSuffix()}`;
    }
}

function applyConsentEffects(): void {
    if (analyticsAllowed.value) {
        grantAnalytics();
    } else {
        revokeAnalytics();
    }
}

function initialize(): void {
    if (initialized) {
        return;
    }

    initialized = true;

    const stored = readConsentCookie();

    if (optOut.value) {
        /*
         * §8.2(3) — a stored accept never overrides an opt-out signal. The
         * cookie is deleted rather than left ignored, and every non-essential
         * store goes with it. This is deliberately not gated on `stored`: the
         * server deletes the `consent` cookie in the same response that carries
         * the opt-out signal, so requiring a readable record here would leave a
         * pre-existing `appearance` or `sidebar_state` behind forever. The
         * banner is not shown at all.
         */
        clearConsentCookie();
        clearNonEssentialStorage();

        return;
    }

    if (!stored) {
        openRequested.value = true;

        return;
    }

    const policyVersion =
        privacy.value?.policyVersion ?? CONSENT_POLICY_VERSION;
    const versionStale = stored.v < policyVersion;
    const serverRejected =
        privacy.value !== undefined && privacy.value.consent === null;

    /*
     * §8.3 — a stored version below the configured one is treated as absent:
     * the banner is re-shown and all non-essential storage is cleared. The
     * server reporting `consent: null` while a cookie is present is the same
     * signal, so the client mirrors it instead of leaving the cookie ignored.
     */
    if (versionStale || serverRejected) {
        clearConsentCookie();
        clearNonEssentialStorage();
        openRequested.value = true;

        return;
    }

    recordState.value = stored;
    analytics.value = stored.analytics;
    advertising.value = stored.advertising;

    if (stored.analytics) {
        grantAnalytics();
    }
}

function decisionFor(categories: ConsentCategories): ConsentDecision {
    if (categories.analytics && categories.advertising) {
        return 'accept_all';
    }

    if (!categories.analytics && !categories.advertising) {
        return 'reject_all';
    }

    return 'custom';
}

/**
 * Reopens the banner with the stored values. Under DNT/GPC it does nothing —
 * the banner is never shown, so there is nothing to reopen.
 */
function open(): void {
    if (optOut.value) {
        return;
    }

    if (!openRequested.value) {
        analytics.value = recordState.value?.analytics === true;
        advertising.value = recordState.value?.advertising === true;
    }

    openRequested.value = true;
}

function save(categories: ConsentCategories): void {
    // §8.2 — the consent cookie is not written at all while an opt-out signal
    // is present, so there is nothing here to record.
    if (optOut.value) {
        return;
    }

    const next: ConsentRecord = {
        v: privacy.value?.policyVersion ?? CONSENT_POLICY_VERSION,
        ts: new Date().toISOString(),
        analytics: categories.analytics === true,
        advertising: categories.advertising === true,
        decision: decisionFor(categories),
    };

    writeConsentCookie(next);

    recordState.value = next;
    analytics.value = next.analytics;
    advertising.value = next.advertising;
    openRequested.value = false;

    applyConsentEffects();
}

function acceptAll(): void {
    save({ analytics: true, advertising: true });
}

function rejectAll(): void {
    save({ analytics: false, advertising: false });
}

/**
 * Forgets the decision entirely rather than recording a rejection, and revokes
 * everything it was holding up.
 */
function withdraw(): void {
    clearConsentCookie();

    recordState.value = null;
    analytics.value = false;
    advertising.value = false;
    openRequested.value = false;

    revokeAnalytics();
}

export function useConsent(): UseConsentReturn {
    // The server render stays purely prop-driven; see `record` and `isOpen`.
    if (typeof window !== 'undefined') {
        initialize();
    }

    return {
        optOut,
        analyticsAllowed,
        adsAllowed,
        analytics,
        advertising,
        record,
        hasDecision,
        isOpen,
        open,
        save,
        acceptAll,
        rejectAll,
        withdraw,
    };
}
