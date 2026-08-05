<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@700&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">

        {{--
            @fluxAppearance above (in partials.head) reads the visitor's stored
            'flux.appearance' preference/system setting and can strip the
            `dark` class we set on <html> — fine for the settings > appearance
            toggle, but this app has no working light theme outside that one
            leftover Fortify page (the product screens are unconditionally
            dark regardless of the toggle). A single add() right after isn't
            enough: Flux's own JS bundle re-syncs the class again once it
            boots (confirmed by tracing every classList call — remove at
            ~170ms from the inline script above, our add at ~270ms, then
            another remove at ~340ms from Flux's bundle), so this keeps
            re-asserting it for as long as the page lives instead of a
            one-shot fix. Deliberately NOT touching localStorage's
            'flux.appearance' — that's the visitor's real, persisted
            preference for the one page that honors it (settings >
            appearance); overwriting it here as a side effect of loading a
            login page would silently break it everywhere else.
        --}}
        <script>
            (function () {
                var html = document.documentElement;
                var forceDark = function () {
                    if (! html.classList.contains('dark')) html.classList.add('dark');
                };
                forceDark();
                new MutationObserver(forceDark).observe(html, { attributes: true, attributeFilter: ['class'] });
            })();
        </script>
    </head>
    {{--
        bg-at-bg/text-at-text are applied unconditionally (not behind a dark:
        variant) so this renders correctly regardless of Flux's own light/dark
        appearance toggle — matching layouts/product.blade.php, which does the
        same for exactly this reason.
    --}}
    <body class="at-shell min-h-screen bg-at-bg font-display text-at-text antialiased">
        <div class="relative flex min-h-svh flex-col items-center justify-center gap-6 overflow-hidden p-6 md:p-10">
            <div class="at-orb-violet pointer-events-none fixed -top-40 -left-30 z-0 size-[480px] animate-[at-drift_14s_ease-in-out_infinite] rounded-full"></div>
            <div class="at-orb-cyan pointer-events-none fixed -right-35 -bottom-45 z-0 size-[520px] animate-[at-drift_18s_ease-in-out_infinite_reverse] rounded-full"></div>
            <div class="at-grid-overlay pointer-events-none fixed inset-0 z-0"></div>

            <div class="relative z-10 flex w-full max-w-sm flex-col gap-2">
                <a href="{{ route('home') }}" class="mb-1 flex flex-col items-center gap-2" wire:navigate>
                    <x-wordmark class="text-[20px]" />
                </a>
                <div class="flex flex-col gap-6 rounded-[14px] border border-at-border bg-at-surface p-6 backdrop-blur-md">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
