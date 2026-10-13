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

    {{-- Inline style to set the HTML background color based on our theme in app.css --}}
    <style nonce="{{ Vite::cspNonce() }}">
        html {
            background-color: oklch(1 0 0);
        }

        html.dark {
            background-color: oklch(0.145 0 0);
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
        <title>{{ config('app.name', 'Laravel') }}</title>
    </x-inertia::head>
</head>
<body class="font-sans antialiased">
    <x-inertia::app />
</body>
</html>
