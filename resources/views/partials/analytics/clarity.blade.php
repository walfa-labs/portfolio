{{--
    Microsoft Clarity loader (§8.4).

    `app.blade.php` includes this only when `$privacy->analyticsAllowed`, so the
    four server-side gates (kill switch, DNT/GPC, stored consent, not `/admin`)
    have already passed by the time it renders. It exists as a partial rather
    than a few lines in the shell because it carries a second, independent
    decision: the request headers a proxy or extension can strip are re-read here
    from the browser itself, and the vendor script is not injected unless they
    agree. A wrong server decision therefore cannot produce a third-party
    request — the worst case is a tag that loads nothing.

    `window.clarity('consent', false)` is queued before the loader so Clarity's
    first sight of us is an explicit refusal; the grant that follows is explicit
    too, which is what §8.4 item 1 asks for. Nothing here ever calls Clarity's
    `identify` command — §8.8 bans it outright, because the contact form is the
    only personal data this site handles and it must never reach an analytics
    payload.
--}}
@php
    $clarityProjectId = isset($privacy) ? $privacy->clarityProjectId : null;
    $consentPolicyVersion = (int) config('consent.policy_version', 1);
@endphp
<script nonce="{{ Vite::cspNonce() }}">
    (function (window, document) {
        'use strict';

        var projectId = @js($clarityProjectId);
        var policyVersion = {{ $consentPolicyVersion }};

        // The vendor's queue shim, so a call made before the loader arrives is
        // replayed in order rather than dropped.
        window.clarity = window.clarity || function () {
            (window.clarity.q = window.clarity.q || []).push(arguments);
        };

        // Queued first, and never undone except by the grant at the bottom of
        // this script: until this site says otherwise, Clarity stores nothing.
        window.clarity('consent', false);

        /*
         * §8.2's union, verbatim: anything that is not an explicit "0" or absent
         * is an opt-out, which covers the legacy "yes"/"on" header forms, and
         * GPC is honoured on its own. This is the same expression
         * `useConsent.ts` uses, so the banner, the loaders and this partial can
         * never disagree about whether the visitor objected.
         */
        var doNotTrack = window.navigator.doNotTrack;
        var optOut =
            !(doNotTrack === '0' || doNotTrack == null) ||
            window.navigator.globalPrivacyControl === true;

        /*
         * The stored decision is re-read from the cookie rather than trusted
         * from the server: a shared cache or a stale HTML response could carry
         * this tag to someone who never consented, and a version bump or a
         * withdrawal must stop it. Anything unparseable counts as no consent —
         * this fails closed. `consent` is in `encryptCookies(except:)` (§8.1),
         * which is what makes it readable here at all.
         */
        var stored = null;
        var match = document.cookie.match(/(?:^|;\s*)consent=([^;]*)/);

        if (match) {
            try {
                stored = JSON.parse(decodeURIComponent(match[1]));
            } catch (error) {
                stored = null;
            }
        }

        var granted =
            stored !== null &&
            typeof stored === 'object' &&
            Number(stored.v) === policyVersion &&
            stored.analytics === true;

        // No id means nothing to load: the env placeholders are empty by
        // design (§8.9) and a request to `clarity.ms/tag/` would be a 404.
        if (optOut || !granted || !projectId) {
            return;
        }

        var script = document.createElement('script');
        script.async = true;
        script.src = 'https://www.clarity.ms/tag/' + projectId;
        script.setAttribute('data-clarity', 'true');

        var first = document.getElementsByTagName('script')[0];
        first.parentNode.insertBefore(script, first);

        window.clarity('consent', true);
    })(window, document);
</script>
