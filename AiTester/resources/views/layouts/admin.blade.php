<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@700&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    </head>
    <body class="at-shell min-h-screen bg-at-bg font-display text-at-text antialiased">
        <div class="relative min-h-screen overflow-x-hidden">
            <div class="at-orb-violet pointer-events-none fixed -top-40 -left-30 z-0 size-[480px] animate-[at-drift_14s_ease-in-out_infinite] rounded-full"></div>
            <div class="at-orb-cyan pointer-events-none fixed -right-35 -bottom-45 z-0 size-[520px] animate-[at-drift_18s_ease-in-out_infinite_reverse] rounded-full"></div>
            <div class="at-grid-overlay pointer-events-none fixed inset-0 z-0"></div>

            <div class="relative z-10 flex min-h-screen flex-col">
                <div class="flex h-14.5 items-center justify-between gap-2.5 border-b border-at-border bg-at-bg/60 px-4 backdrop-blur-lg lg:px-6.5">
                    <div class="flex items-center gap-3.5">
                        <a href="{{ route('admin.dashboard') }}" wire:navigate>
                            <x-wordmark class="text-[16px]" />
                        </a>
                        <div class="font-data rounded-md bg-at-chip px-1.75 py-0.5 text-[10.5px] font-bold text-at-muted-2">{{ __('ADMIN') }}</div>
                        <div class="text-[15px] font-semibold">{{ $title ?? '' }}</div>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <flux:dropdown position="bottom" align="end">
                            <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" />
                            <flux:menu>
                                <flux:menu.item :href="route('dashboard')" icon="arrow-left" wire:navigate>
                                    {{ __("Retour à l'app") }}
                                </flux:menu.item>
                                <form method="POST" action="{{ route('logout') }}" class="w-full">
                                    @csrf
                                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer">
                                        {{ __('Log out') }}
                                    </flux:menu.item>
                                </form>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto p-4 sm:p-7 sm:px-8">
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
