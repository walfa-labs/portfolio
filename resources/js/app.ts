import { createInertiaApp } from '@inertiajs/vue3';
import type { Component, FunctionalComponent, Slots } from 'vue';
import { h } from 'vue';
import PrivacyRoot from '@/components/privacy/PrivacyRoot.vue';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

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
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
