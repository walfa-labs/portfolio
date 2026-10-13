<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script nonce="{{ Vite::cspNonce() }}">
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color before the stylesheet paints (§6.2.4) --}}
        <style nonce="{{ Vite::cspNonce() }}">
            html {
                background-color: #f4f1e7;
            }

            html.dark {
                background-color: #17150f;
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        {{--
            The CSP nonce for Inertia's client bootstrap (§8.6). `app.ts` runs
            before any page props exist, so it reads the nonce from the document
            rather than from the `privacy` prop; `SecurityHeaders` has already
            generated it by the time this shell renders.
        --}}
        <script nonce="{{ Vite::cspNonce() }}">
            window.__cspNonce = @json(Vite::cspNonce());
        </script>

        {{--
            The Clarity loader, only when every server-side gate passed (§8.4 item 1).
            `$privacy` is absent when an error page renders outside the `web` group —
            an unmatched-route 404 or a maintenance 503 — so the shell has to survive
            that rather than throw a second error on the error page.
        --}}
        @includeWhen(($privacy ?? null)?->analyticsAllowed ?? false, 'partials.analytics.clarity', ['privacy' => $privacy ?? null])

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name') }} — Software Engineer</title>
            <meta name="description" content="Portfolio of {{ config('app.name') }}: experience, projects, publications and writing.">
            <link rel="alternate" type="application/atom+xml" title="{{ config('app.name') }} — Writing" href="{{ url('/writing/feed.atom') }}">
            <meta property="og:type" content="website">
            <meta property="og:title" content="{{ config('app.name') }} — Software Engineer">
            <meta property="og:description" content="Portfolio of {{ config('app.name') }}: experience, projects, publications and writing.">
            <meta property="og:url" content="{{ config('app.url') }}">
            <meta property="og:image" content="{{ rtrim(config('app.url'), '/') }}/og/default.png">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta property="og:site_name" content="{{ config('app.name') }}">
            <meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}">
        </x-inertia::head>
    </head>
    <body>
        <a href="#main-content" class="skip-link">Skip to main content</a>

        <div
            id="route-announcer"
            role="status"
            aria-live="polite"
            aria-atomic="true"
            class="sr-only"
        ></div>

        <x-inertia::app />
    </body>
</html>
