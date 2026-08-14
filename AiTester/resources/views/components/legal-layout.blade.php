@props(['title', 'updated' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title }} — {{ config('app.name') }}</title>
        <meta name="robots" content="noindex">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@700&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="at-shell min-h-screen bg-at-bg font-display text-at-text antialiased">
        <div class="relative min-h-screen overflow-x-hidden">
            <div class="at-orb-violet pointer-events-none fixed -top-40 -left-30 z-0 size-[480px] rounded-full"></div>
            <div class="at-orb-cyan pointer-events-none fixed -right-35 -bottom-45 z-0 size-[520px] rounded-full"></div>
            <div class="at-grid-overlay pointer-events-none fixed inset-0 z-0"></div>

            <div class="relative z-10">
                <header class="sticky top-0 z-20 border-b border-at-border bg-at-bg/70 backdrop-blur-lg">
                    <div class="mx-auto flex h-16 max-w-3xl items-center justify-between px-6">
                        <a href="{{ route('home') }}" class="flex items-center">
                            <x-wordmark class="text-[17px]" />
                        </a>
                        <a href="{{ route('home') }}" class="text-[13px] font-medium text-at-muted-2 hover:text-at-text">
                            &larr; {{ __("Retour à l'accueil") }}
                        </a>
                    </div>
                </header>

                <main class="mx-auto max-w-3xl px-6 py-16">
                    <h1 class="text-[30px] font-bold tracking-tight">{{ $title }}</h1>
                    @if ($updated)
                        <p class="mt-2 text-[12.5px] text-at-muted">{{ __('Dernière mise à jour :') }} {{ $updated }}</p>
                    @endif

                    <div class="at-legal-content mt-10 flex flex-col gap-8 text-[14px] leading-relaxed text-at-muted-2">
                        {{ $slot }}
                    </div>
                </main>

                <footer class="border-t border-at-border">
                    <div class="mx-auto flex max-w-3xl flex-col items-center justify-between gap-4 px-6 py-8 text-[12px] text-at-muted sm:flex-row">
                        <x-wordmark class="text-[13px] text-at-muted-2" />
                        <div class="flex items-center gap-4">
                            <a href="{{ route('legal.mentions') }}" class="hover:text-at-text">{{ __('Mentions légales') }}</a>
                            <a href="{{ route('legal.cgu') }}" class="hover:text-at-text">{{ __('CGU/CGV') }}</a>
                            <a href="{{ route('legal.privacy') }}" class="hover:text-at-text">{{ __('Confidentialité') }}</a>
                        </div>
                    </div>
                </footer>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
