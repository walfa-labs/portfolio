<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import PrivacyChoicesLink from '@/components/privacy/PrivacyChoicesLink.vue';
import SeoHead from '@/components/public/SeoHead.vue';
import { useConsent } from '@/composables/useConsent';

type StorageRow = {
    name: string;
    kind: string;
    setBy: string;
    essential: string;
    purpose: string;
    lifetime: string;
    encryption: string;
};

type RetentionRow = {
    what: string;
    howLong: string;
    why: string;
};

type Props = {
    contactEmail: string | null;
    policyVersion: number;
    lastUpdated: string;
    versionHistory: { version: number; effective: string; summary: string }[];
    title: string;
    description: string;
    canonical: string;
    jsonLd: Record<string, unknown>[];
    noindex: boolean;
};

defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: '/' },
            { title: 'Privacy', href: '/privacy' },
        ],
    },
});

/*
 * The stored decision comes from `useConsent()`, the module that owns the
 * cookie, rather than from a page prop or a second parse of `document.cookie`.
 * That buys two things: the version rule is applied in exactly one place — a
 * record written against an older `CONSENT_POLICY_VERSION` is treated as absent
 * (and deleted) instead of being shown as a decision the site is not honouring —
 * and the panel follows the live state, so saving or withdrawing a choice
 * updates it immediately rather than leaving a stale version and timestamp on
 * screen. `optOut` needs no special case: under DNT/GPC no cookie is ever
 * written, so there is never a record to show.
 */
const { record } = useConsent();

/*
 * Formatted by hand rather than with `toLocaleString()`: the output is then
 * identical on the server and in the browser, so an SSR pass cannot produce a
 * hydration mismatch over a date.
 */
const recordedAt = computed(() => {
    const stored = record.value?.ts;

    if (!stored) {
        return '';
    }

    const parsed = new Date(stored);

    if (Number.isNaN(parsed.getTime())) {
        return stored;
    }

    return `${parsed.toISOString().slice(0, 16).replace('T', ' ')} UTC`;
});

/*
 * The §8.1 cookie and storage inventory, reproduced in full — including the
 * "Essential?" column, which is the whole point of the table.
 */
const storageRows: StorageRow[] = [
    {
        name: 'laravel-session (SESSION_COOKIE)',
        kind: 'cookie',
        setBy: 'Laravel session',
        essential: 'Essential',
        purpose: 'Session id, CSRF binding, flash messages, authentication',
        lifetime: '120 min idle',
        encryption: 'Encrypted',
    },
    {
        name: 'XSRF-TOKEN',
        kind: 'cookie',
        setBy: 'PreventRequestForgery',
        essential: 'Essential',
        purpose:
            "CSRF token read by Inertia's XHR client and sent back as X-XSRF-TOKEN",
        lifetime: '5 days (60 × 120 min)',
        encryption: 'Encrypted; HttpOnly=false by design',
    },
    {
        name: 'remember_web_<sha1>',
        kind: 'cookie',
        setBy: 'SessionGuard',
        essential: 'Essential',
        purpose: 'Remember-me recaller, single administrator account',
        lifetime: '400 days',
        encryption: 'Encrypted',
    },
    {
        name: 'appearance',
        kind: 'cookie + localStorage key',
        setBy: 'useAppearance.ts',
        essential: 'Non-essential (preference)',
        purpose: 'light / dark / system',
        lifetime: '365 days',
        encryption: 'Exempt',
    },
    {
        name: 'sidebar_state',
        kind: 'cookie',
        setBy: 'SidebarProvider.vue',
        essential: 'Non-essential (preference)',
        purpose: 'Sidebar expanded or collapsed',
        lifetime: '7 days',
        encryption: 'Exempt',
    },
    {
        name: 'inertiaLocationVisit',
        kind: 'sessionStorage',
        setBy: 'Inertia v3',
        essential: 'Non-essential',
        purpose: 'Location-visit bookkeeping after a redirect',
        lifetime: 'Tab lifetime',
        encryption: 'n/a',
    },
    {
        name: 'historyKey, historyIv',
        kind: 'sessionStorage',
        setBy: 'Inertia v3',
        essential: 'Non-essential',
        purpose: 'History-encryption key and IV (only when enabled)',
        lifetime: 'Tab lifetime',
        encryption: 'n/a',
    },
    {
        name: 'consent',
        kind: 'cookie',
        setBy: 'useConsent.ts',
        essential: 'Essential (compliance record)',
        purpose:
            'Versioned consent decision, re-read server-side for the content security policy and SSR gating',
        lifetime: '180 days',
        encryption:
            'Exempt (add to encryptCookies(except:)); client-written, treated as untrusted',
    },
    {
        name: '_clck, _clsk',
        kind: 'cookie',
        setBy: 'Microsoft Clarity',
        essential: 'Non-essential',
        purpose:
            'Clarity user id and preferences; joins page views into one recording',
        lifetime: 'Not published by the vendor',
        encryption: 'n/a',
    },
    {
        name: 'CLID, MUID, ANONCHK, MR, SM',
        kind: 'cookie (third party, clarity.ms / bing.com)',
        setBy: 'Microsoft Clarity / Microsoft',
        essential: 'Non-essential',
        purpose: 'Cross-site identity, Microsoft advertising sync',
        lifetime: 'Not published by the vendor',
        encryption: 'n/a',
    },
    {
        name: 'Google advertising cookies on *.google.com and *.doubleclick.net',
        kind: 'cookie (third party)',
        setBy: 'Google AdSense',
        essential: 'Non-essential',
        purpose: 'Ad delivery, frequency capping, measurement',
        lifetime: 'Names and lifetimes not published by the vendor',
        encryption: 'n/a',
    },
];

const retentionRows: RetentionRow[] = [
    {
        what: 'Consent record (the consent cookie)',
        howLong: '180 days',
        why: 'Deliberately shorter than the nine months the analytics vendor accepts, so our record always expires first.',
    },
    {
        what: 'Session',
        howLong: '120 minutes of inactivity',
        why: 'Keeps you signed in to the administration area, and carries the form token.',
    },
    {
        what: 'Remember-me cookie',
        howLong: '400 days',
        why: 'Only ever written for the single administrator account, and only if that box is ticked.',
    },
    {
        what: 'Appearance and sidebar preferences',
        howLong: '365 days and 7 days',
        why: 'Non-essential conveniences; not written at all while a Do Not Track or Global Privacy Control signal is present.',
    },
    {
        what: 'Microsoft Clarity recordings',
        howLong: '30 days',
        why: 'Set by the vendor and not configurable on the free tier.',
    },
    {
        what: 'Clarity favourite and randomly sampled recordings, and labels',
        howLong: 'Up to 9 months',
        why: 'Set by the vendor and not configurable on the free tier.',
    },
    {
        what: 'Clarity consent validity',
        howLong: 'Up to 9 months',
        why: 'Vendor-side; our own consent record expires sooner.',
    },
    {
        what: 'Contact form messages',
        howLong: '24 months',
        why: 'Long enough to answer a request, then deleted automatically by a scheduled sweep.',
    },
];

const externalLinks = {
    microsoftPrivacy: 'https://privacy.microsoft.com/privacystatement',
    googlePartnerSites:
        'https://policies.google.com/technologies/partner-sites',
    googleAdSettings: 'https://adssettings.google.com/',
    microsoftOptOut: 'https://account.microsoft.com/privacy/ad-settings',
};
</script>

<template>
    <SeoHead
        :title="title"
        :description="description"
        :canonical="canonical"
        :json-ld="jsonLd"
        :noindex="noindex"
    />

    <article class="mx-auto max-w-[80ch] space-y-12">
        <header class="space-y-4">
            <Heading as="h1" variant="display" title="Privacy" />

            <p class="text-lead text-muted-foreground">
                What this site stores on your device, what it loads from other
                companies, and what you can do about both. It is written to be
                read, not to be survived.
            </p>

            <dl class="flex flex-wrap gap-x-6 gap-y-2 text-meta">
                <div class="flex gap-2">
                    <dt class="font-medium">Last updated</dt>
                    <dd>
                        <time :datetime="lastUpdated">{{ lastUpdated }}</time>
                    </dd>
                </div>
                <div class="flex gap-2">
                    <dt class="font-medium">Policy version</dt>
                    <dd class="tabular-nums">{{ policyVersion }}</dd>
                </div>
            </dl>
        </header>

        <section aria-label="Who is responsible" class="space-y-3">
            <Heading as="h2" title="Who is responsible" />

            <p class="text-muted-foreground">
                This is a personal site, so the data controller is the person
                whose portfolio it is — the author named in the site title.
                There is no company behind it and no data protection officer.
            </p>

            <p class="text-muted-foreground">
                <template v-if="contactEmail">
                    Write to
                    <!-- §8.4 item 4 — anything rendering an email address is masked. -->
                    <a
                        :href="`mailto:${contactEmail}`"
                        data-clarity-mask="true"
                        class="underline decoration-2 underline-offset-4 hover:text-primary"
                        >{{ contactEmail }}</a
                    >
                    or use the
                    <Link
                        href="/contact"
                        class="underline decoration-2 underline-offset-4 hover:text-primary"
                        >contact form</Link
                    >. The contact form is the fastest route and the one this
                    policy assumes for every request below.
                </template>
                <template v-else>
                    Use the
                    <Link
                        href="/contact"
                        class="underline decoration-2 underline-offset-4 hover:text-primary"
                        >contact form</Link
                    >
                    — it is the only channel this site offers for a request
                    about your data.
                </template>
            </p>

            <section class="space-y-2">
                <h3 class="font-display text-xl font-bold tracking-tight">
                    Version history
                </h3>
                <ul class="space-y-3">
                    <li
                        v-for="entry in versionHistory"
                        :key="entry.version"
                        class="rounded-md border-2 border-ink bg-card p-4"
                    >
                        <p
                            class="font-mono text-label tracking-widest uppercase"
                        >
                            Version {{ entry.version }} —
                            <time :datetime="entry.effective">{{
                                entry.effective
                            }}</time>
                        </p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ entry.summary }}
                        </p>
                    </li>
                </ul>
                <p class="text-sm text-muted-foreground">
                    A stored consent decision records the version it was given
                    against. When the version changes, the older decision stops
                    counting and the choice is asked again.
                </p>
            </section>
        </section>

        <section
            aria-label="What this site stores on your device"
            class="space-y-4"
        >
            <Heading as="h2" title="What this site stores on your device" />

            <p class="text-muted-foreground">
                Every cookie and storage key the site can write, whether it is
                essential, and how long it lives. Essential entries are the ones
                that make a form work at all; they are never used for analytics
                or advertising.
            </p>

            <!--
                §7.2 (2.1.1) — the table is wider than the column at every
                viewport, so the scroll container is a scrollable region with no
                focusable content of its own. `tabindex="0"` puts it in the tab
                order so it can be scrolled with the keyboard, and the region
                takes its name from the caption rather than new copy.
            -->
            <div
                class="overflow-x-auto"
                tabindex="0"
                role="region"
                aria-labelledby="storage-table-caption"
            >
                <table
                    class="w-full min-w-[64rem] border-collapse border-2 border-ink text-left text-sm"
                >
                    <caption
                        id="storage-table-caption"
                        class="mb-3 text-left text-meta text-muted-foreground"
                    >
                        Cookies and storage keys used by this site, with the
                        Essential? column stating whether each one can be
                        refused.
                    </caption>
                    <thead>
                        <tr class="border-b-2 border-ink">
                            <th scope="col" class="p-3 font-semibold">Name</th>
                            <th scope="col" class="p-3 font-semibold">Kind</th>
                            <th scope="col" class="p-3 font-semibold">
                                Set by
                            </th>
                            <th scope="col" class="p-3 font-semibold">
                                Essential?
                            </th>
                            <th scope="col" class="p-3 font-semibold">
                                Purpose
                            </th>
                            <th scope="col" class="p-3 font-semibold">
                                Lifetime
                            </th>
                            <th scope="col" class="p-3 font-semibold">
                                Encrypted / exempt today
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in storageRows"
                            :key="row.name"
                            class="border-b border-ink/40 align-top"
                        >
                            <th
                                scope="row"
                                class="p-3 font-mono text-meta font-medium"
                            >
                                {{ row.name }}
                            </th>
                            <td class="p-3">{{ row.kind }}</td>
                            <td class="p-3 font-mono text-meta">
                                {{ row.setBy }}
                            </td>
                            <td class="p-3 font-semibold">
                                {{ row.essential }}
                            </td>
                            <td class="p-3">{{ row.purpose }}</td>
                            <td class="p-3">{{ row.lifetime }}</td>
                            <td class="p-3">{{ row.encryption }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section aria-label="Analytics" class="space-y-3">
            <Heading as="h2" title="Analytics" />

            <p class="text-muted-foreground">
                This site uses
                <strong>Microsoft Clarity</strong> to understand how pages are
                read: which sections people scroll to, where they get stuck, and
                which links they use. It is only ever loaded after you accept
                analytics, it is never loaded in the administration area, and it
                is never loaded at all while a Do Not Track or Global Privacy
                Control signal is present.
            </p>

            <p class="text-muted-foreground">
                Clarity records page views, clicks and scrolling, and stores
                what it collects in Microsoft Azure. Recordings are kept for 30
                days; favourite and randomly sampled recordings and labels can
                be kept for up to nine months. Those periods are the vendor's
                and are not configurable on the free tier — that is a limitation
                of the product, disclosed rather than hidden.
            </p>

            <p class="text-muted-foreground">
                <strong>Masking.</strong> Clarity runs in its strictest masking
                mode: the page is masked by default and only non-personal public
                content — the navigation, the page heading and content titles —
                is unmasked, so heatmaps stay useful without putting names,
                employers or free text into a recording. The contact form, every
                settings page and anything showing an email address is
                explicitly masked. Microsoft never receives a name, an email
                address or the text of a message from this site.
            </p>

            <p class="text-muted-foreground">
                Microsoft's own description of what Clarity collects is in the
                <a
                    :href="externalLinks.microsoftPrivacy"
                    rel="noopener noreferrer"
                    class="underline decoration-2 underline-offset-4 hover:text-primary"
                    >Microsoft Privacy Statement</a
                >.
            </p>
        </section>

        <section aria-label="Advertising" class="space-y-3">
            <Heading as="h2" title="Advertising" />

            <p class="text-muted-foreground">
                <strong>Google AdSense</strong> may serve one ad unit, and only
                on an individual article page. It never appears on the home
                page, on a list, on a tag page or anywhere in the administration
                area.
            </p>

            <p class="text-muted-foreground">
                Ads are always requested as
                <strong>non-personalised</strong>: no profile of you is used to
                choose them, and nothing you type into this site is ever sent to
                Google. The ad slot is only created after you accept
                advertising. Non-personalised ads still use cookies for
                frequency capping and aggregate reporting, which is why consent
                is asked for before the advertising script is loaded at all.
            </p>

            <p
                class="rounded-md border-2 border-ink bg-card p-4 text-muted-foreground"
            >
                Google explains how it uses data from sites that show its ads in
                <a
                    :href="externalLinks.googlePartnerSites"
                    rel="noopener noreferrer"
                    class="font-semibold underline decoration-2 underline-offset-4 hover:text-primary"
                    >How Google uses information from sites or apps that use our
                    services</a
                >. You can change or turn off personalised advertising at
                <a
                    :href="externalLinks.googleAdSettings"
                    rel="noopener noreferrer"
                    class="underline decoration-2 underline-offset-4 hover:text-primary"
                    >Google Ad Settings</a
                >.
            </p>
        </section>

        <section
            aria-label="Why this site may process your data"
            class="space-y-3"
        >
            <Heading as="h2" title="Why this site may process your data" />

            <ul class="list-disc space-y-2 pl-5 text-muted-foreground">
                <li>
                    <strong>Consent</strong> — analytics and advertising.
                    Nothing in either category is loaded before you agree, and
                    you can withdraw at any time.
                </li>
                <li>
                    <strong>Legitimate interests</strong> — keeping the site
                    online and abuse-free: rate limiting the contact form, and
                    recording the IP address and browser of a submission so a
                    flood can be traced. This is the minimum needed to run a
                    public form.
                </li>
                <li>
                    <strong>Legal obligation</strong> — answering a request
                    about your data, and keeping the record of it for as long as
                    that takes.
                </li>
            </ul>

            <p class="text-muted-foreground">
                There is no account to create, no newsletter, no profiling and
                no automated decision-making. The contact form is the only place
                on this site where you type anything about yourself.
            </p>
        </section>

        <section id="choices" aria-label="Your choices" class="space-y-3">
            <Heading as="h2" title="Your choices" />

            <p class="text-muted-foreground">
                Two browser signals are honoured, and honoured in full:
                <strong>Do Not Track</strong> (<code>DNT: 1</code>) and
                <strong>Global Privacy Control</strong>
                (<code>Sec-GPC: 1</code>). When either is present, nothing
                non-essential runs: no analytics, no advertising, no preference
                cookies and no consent cookie. The consent banner is not shown
                either, because every possible answer would produce the same
                state — asking would be friction with no purpose.
            </p>

            <p class="text-muted-foreground">
                Neither signal ever <em>grants</em> consent. An absent signal
                means no objection has been recorded, not "track me". If you
                want analytics to run, turn the signal off and the banner will
                offer you the choice.
            </p>

            <p class="text-muted-foreground">
                You can change your mind at any time by reopening the cookie
                choices. Withdrawal takes effect immediately: the analytics and
                advertising scripts are not loaded again, and anything already
                running is told to stop.
            </p>

            <!--
                The `#choices` anchor (§8.7 item 9) hosts the one re-opening
                control. The stored record is shown beside it rather than in a
                separate section, because the decision and the thing that
                changes it belong together — and it is the visitor's own
                evidence of what they agreed to and when.
            -->
            <div class="space-y-4 rounded-md border-2 border-ink bg-card p-4">
                <PrivacyChoicesLink />

                <dl
                    v-if="record"
                    class="flex flex-wrap gap-x-6 gap-y-2 text-meta"
                >
                    <div class="flex gap-2">
                        <dt class="font-medium">Stored choice, version</dt>
                        <dd class="tabular-nums">{{ record.v }}</dd>
                    </div>
                    <div class="flex gap-2">
                        <dt class="font-medium">Recorded</dt>
                        <dd>
                            <time :datetime="record.ts">{{ recordedAt }}</time>
                        </dd>
                    </div>
                </dl>

                <p v-if="record" class="text-meta text-muted-foreground">
                    That decision is stored on this device only. It stops
                    counting when the policy version changes, and it is deleted
                    rather than left ignored if you withdraw it or turn on a Do
                    Not Track signal.
                </p>

                <p v-else class="text-meta text-muted-foreground">
                    No consent decision is stored on this device, so nothing
                    non-essential is running.
                </p>
            </div>

            <section class="space-y-2">
                <h3 class="font-display text-xl font-bold tracking-tight">
                    What withdrawal cannot reach
                </h3>
                <p class="text-muted-foreground">
                    This site can delete its own cookies. It cannot delete
                    cookies set by Microsoft on <code>clarity.ms</code> and
                    <code>bing.com</code>, or by Google on
                    <code>*.google.com</code> and
                    <code>*.doubleclick.net</code> — those belong to other
                    domains and only your browser can remove them. To clear
                    them, use your browser's cookie settings, or:
                </p>
                <ul class="list-disc space-y-2 pl-5 text-muted-foreground">
                    <li>
                        <a
                            :href="externalLinks.googleAdSettings"
                            rel="noopener noreferrer"
                            class="underline decoration-2 underline-offset-4 hover:text-primary"
                            >Google Ad Settings</a
                        >
                        — controls the advertising data Google holds about you.
                    </li>
                    <li>
                        <a
                            :href="externalLinks.microsoftOptOut"
                            rel="noopener noreferrer"
                            class="underline decoration-2 underline-offset-4 hover:text-primary"
                            >Microsoft ad settings</a
                        >
                        — the equivalent for Microsoft.
                    </li>
                </ul>
                <p class="text-muted-foreground">
                    Analytics withdrawal also cannot reach a recording that has
                    already been sent. Deleting those is a manual step in the
                    Clarity dashboard, because the free tier offers no
                    per-person delete: ask and it will be done.
                </p>
            </section>
        </section>

        <section aria-label="How long your data is kept" class="space-y-4">
            <Heading as="h2" title="How long your data is kept" />

            <!-- Same scroll-region contract as the storage table above: it fits
                 at 1280 px, but `min-w-[48rem]` overflows below that. -->
            <div
                class="overflow-x-auto"
                tabindex="0"
                role="region"
                aria-labelledby="retention-table-caption"
            >
                <table
                    class="w-full min-w-[48rem] border-collapse border-2 border-ink text-left text-sm"
                >
                    <caption
                        id="retention-table-caption"
                        class="mb-3 text-left text-meta text-muted-foreground"
                    >
                        Retention periods for everything this site stores.
                    </caption>
                    <thead>
                        <tr class="border-b-2 border-ink">
                            <th scope="col" class="p-3 font-semibold">
                                What is kept
                            </th>
                            <th scope="col" class="p-3 font-semibold">
                                How long
                            </th>
                            <th scope="col" class="p-3 font-semibold">
                                Why that period
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in retentionRows"
                            :key="row.what"
                            class="border-b border-ink/40 align-top"
                        >
                            <th scope="row" class="p-3 font-medium">
                                {{ row.what }}
                            </th>
                            <td class="p-3">{{ row.howLong }}</td>
                            <td class="p-3 text-muted-foreground">
                                {{ row.why }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section aria-label="Your rights" class="space-y-3">
            <Heading as="h2" title="Your rights" />

            <p class="text-muted-foreground">
                You can ask for any of the following, and the answer does not
                depend on where you live:
            </p>

            <dl class="space-y-3">
                <div>
                    <dt class="font-semibold">Access</dt>
                    <dd class="text-muted-foreground">
                        A copy of everything this site holds about you.
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Rectification</dt>
                    <dd class="text-muted-foreground">
                        Correction of anything inaccurate.
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Erasure</dt>
                    <dd class="text-muted-foreground">
                        Deletion of your data. In practice that means deleting
                        the contact message and its notification email, and
                        deleting any Clarity recording by hand.
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Restriction</dt>
                    <dd class="text-muted-foreground">
                        A hold on further processing while a question about your
                        data is being resolved.
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Objection</dt>
                    <dd class="text-muted-foreground">
                        An objection to processing based on legitimate
                        interests.
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Portability</dt>
                    <dd class="text-muted-foreground">
                        Your data in a structured, machine-readable file.
                    </dd>
                </div>
            </dl>

            <section class="space-y-2">
                <h3 class="font-display text-xl font-bold tracking-tight">
                    If you are in California
                </h3>
                <p class="text-muted-foreground">
                    You can ask to know what is collected, ask for it to be
                    deleted, and ask to opt out of the sale or sharing of
                    personal information.
                    <strong
                        >This site does not sell or share personal information,
                        and has no mechanism to do so.</strong
                    >
                    There is nothing to opt out of, and no third party receives
                    your data for cross-context behavioural advertising.
                </p>
            </section>

            <section class="space-y-2">
                <h3 class="font-display text-xl font-bold tracking-tight">
                    How to make a request
                </h3>
                <p class="text-muted-foreground">
                    Use the
                    <Link
                        href="/contact"
                        class="underline decoration-2 underline-offset-4 hover:text-primary"
                        >contact form</Link
                    >
                    and pick the matching request type — access, rectification,
                    erasure, objection or other. The type is what lets the
                    request be triaged without a follow-up email asking what you
                    meant.
                </p>
                <p class="text-muted-foreground">
                    You will get an acknowledgement within
                    <strong>7 days</strong> and a substantive answer within
                    <strong>30 days</strong>. If a request is unusually complex,
                    you will be told before the 30 days are up and given the
                    date by which to expect the answer.
                </p>
            </section>
        </section>

        <section aria-label="Children" class="space-y-3">
            <Heading as="h2" title="Children" />

            <p class="text-muted-foreground">
                This site is a professional portfolio. It is not directed at
                anyone under 18, it does not knowingly collect anything from
                anyone under 18, and analytics of the kind it uses is not
                permitted on sites aimed at children — which is one reason it is
                never loaded without an explicit choice.
            </p>
        </section>

        <section aria-label="How this page is built" class="space-y-3">
            <Heading as="h2" title="How this page is built" />

            <p class="text-muted-foreground">
                The two inventories above are real tables with captions and
                scoped headers, so a screen reader can announce which column a
                value belongs to. Nothing here is conveyed by colour alone, and
                the headings descend one level at a time. If any part of this
                page is hard to use, that is a defect worth reporting through
                the contact form.
            </p>
        </section>
    </article>
</template>
