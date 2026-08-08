<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class([
    'dark' => ($appearance ?? 'system') == 'dark',
    'cicto-landing' => ($page['component'] ?? '') === 'welcome',
])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
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
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }

            /* Landing page ground. Keep in sync with --color-surface in
               resources/css/app.css -- this paints the first frame and the
               overscroll canvas before the stylesheet loads. The client's
               design has no dark variant, so both cases are the same. */
            html.cicto-landing,
            html.cicto-landing.dark {
                background-color: #eff6fe;
            }
        </style>

        {{-- Link preview card for Facebook, Messenger, Instagram, Viber, etc.

             These live here rather than in the Inertia <Head> of a page because
             the crawlers that read them do not run JavaScript: they fetch the
             raw HTML once and never hydrate. Anything React renders is
             invisible to them unless the SSR server happens to be up, so the
             card has to be part of the shell.

             public/og-image.png is the client's logo flattened onto white at
             1200x630 (transparent PNGs get composited on black by Messenger).
             The mark sits inside the centre 630x630 square so a 1:1 crop --
             what Messenger and Instagram DMs do -- still shows all of it.
             Regenerate it from resources/js/assets/cicto-baliwag-logo.png if
             the client ever sends a new logo. --}}
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name', 'CICTO') }}">
        <meta property="og:title" content="CICTO Document Tracking System">
        <meta property="og:description" content="Track, manage and monitor your documents efficiently. The CICTO Baliwag document tracking system with QR code scanning, real-time tracking and detailed reports.">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="{{ asset('og-image.png') }}">
        <meta property="og:image:type" content="image/png">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="CICTO Baliwag logo">
        <meta property="og:locale" content="en_PH">

        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="CICTO Document Tracking System">
        <meta name="twitter:description" content="Track, manage and monitor your documents efficiently. The CICTO Baliwag document tracking system with QR code scanning, real-time tracking and detailed reports.">
        <meta name="twitter:image" content="{{ asset('og-image.png') }}">
        <meta name="twitter:image:alt" content="CICTO Baliwag logo">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
