import { createInertiaApp } from '@inertiajs/vue3';
import type { Component, FunctionalComponent, Slots } from 'vue';
import { createApp, h } from 'vue';
import RouteAnnouncer from '@/components/a11y/RouteAnnouncer.vue';
import PrivacyRoot from '@/components/privacy/PrivacyRoot.vue';
import { initializeTheme } from '@/composables/useAppearance';
import { useScrollFocusIntoView } from '@/composables/useScrollFocusIntoView';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// This will set light / dark mode on page load...
initializeTheme();

/*
 * §3.1.2 / §8.3 — every layout the resolver returns is wrapped, so the consent
 * banner renders on the public, auth, admin, settings and layout-less surfaces
 * alike. The wrapper forwards the page props and the layout's own default slot
 * (`PrivacyRoot` renders `<slot />` + the banner), and it is memoised: the
 * resolver runs on every navigation, and a fresh component identity each time
 * would remount the whole shell and throw the sidebar's state away.
 */
const privacyLayouts = new WeakMap<
    Component,
    FunctionalComponent<Record<string, unknown>>
>();

const withPrivacy = (
    Inner: Component,
): FunctionalComponent<Record<string, unknown>> => {
    const cached = privacyLayouts.get(Inner);

    if (cached) {
        return cached;
    }

    const Wrapped: FunctionalComponent<Record<string, unknown>> = (
        props,
        { slots }: { slots: Slots },
    ) =>
        h(PrivacyRoot as Component, props, {
            default: () => h(Inner, props, slots),
        });

    privacyLayouts.set(Inner, Wrapped);

    return Wrapped;
};

/*
 * §8.6 / pinned 24 — `app.ts` runs before any page prop exists, so the nonce is
 * read from the value the Blade shell emits on `window` rather than from the
 * shared `privacy.cspNonce` prop.
 */
const cspNonce =
    typeof window === 'undefined'
        ? undefined
        : (window as Window & { __cspNonce?: string }).__cspNonce;

void createInertiaApp({
    nonce: cspNonce,
    title: (title) =>
        !title
            ? appName
            : title.includes(appName)
              ? title
              : `${title} - ${appName}`,
    /*
     * `public/` gets the public shell; `admin/` gets the sidebar shell plus the
     * admin content wrapper (§3.1.2, §5.8). `withPrivacy` wraps all five cases,
     * including the two array cases: §8.3 puts `PrivacyChoicesLink` in
     * `AppLayout`'s sidebar footer, which renders on `/admin` and `/settings`
     * too, and an unwrapped array would give a re-open button whose banner can
     * never render.
     */
    layout: (name) => {
        switch (true) {
            case name.startsWith('public/'):
                return withPrivacy(PublicLayout);
            case name.startsWith('admin/'):
                return [withPrivacy(AppLayout), AdminLayout];
            case name.startsWith('auth/'):
                return withPrivacy(AuthLayout);
            case name.startsWith('settings/'):
                return [withPrivacy(AppLayout), SettingsLayout];
            default:
                return withPrivacy(AppLayout);
        }
    },
    withApp: (app, { ssr }) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.setAttribute('data-programmatic-focus', '');
                    el.focus();
                }
            },
        });

        if (ssr) {
            return;
        }

        createApp(RouteAnnouncer).mount('#route-announcer');
        useScrollFocusIntoView();
    },
    progress: {
        // Guarded because the SSR entry evaluates this module in Node, where `document` does not exist.
        color:
            typeof document !== 'undefined' &&
            document.documentElement.classList.contains('dark')
                ? '#6FD3B4'
                : '#19594A',
    },
});

// This will listen for flash toast data from the server...
initializeFlashToast();
