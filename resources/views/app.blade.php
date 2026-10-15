@php
    $appearance = $appearance ?? 'system';
    $isDark = $appearance === 'dark'
        || ($appearance === 'omarchy' && ($omarchy?->mode ?? 'dark') === 'dark');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => $isDark]) data-omarchy-mode="{{ $omarchy?->mode }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="A social network for developers. Sign in with GitHub, commit plain text to a shared log.">
        @if ($appearance === 'omarchy' && $omarchy)
            <meta name="theme-color" content="{{ $omarchy->tokens['--background'] }}">
        @else
            <meta name="theme-color" content="#f6f7f8" media="(prefers-color-scheme: light)">
            <meta name="theme-color" content="#111112" media="(prefers-color-scheme: dark)">
        @endif

        <meta property="og:type" content="website">
        <meta property="og:title" content="devnull">
        <meta property="og:description" content="A social network for developers. Sign in with GitHub, commit plain text to a shared log.">
        <meta property="og:image" content="{{ url('/og.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta name="twitter:card" content="summary_large_image">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance }}';
                const omarchyMode = document.documentElement.dataset.omarchyMode;

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                } else if (appearance === 'omarchy') {
                    document.documentElement.classList.toggle('dark', omarchyMode === 'dark');
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #f6f7f8;
            }

            html.dark {
                background-color: #111112;
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])

        {{-- Omarchy theme tokens, layered on top of app.css for the selected Omarchy theme --}}
        @if ($appearance === 'omarchy' && $omarchy)
            <style>
                html:root,
                html.dark {
                    background-color: {{ $omarchy->tokens['--background'] }};
                    @foreach ($omarchy->tokens as $property => $value)
                        {{ $property }}: {{ $value }};
                    @endforeach
                    @if ($omarchy->font)
                        --font-mono: "{{ $omarchy->font }}", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                    @endif
                }
            </style>
        @endif

        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
