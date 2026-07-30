@php
    $project = auth()->user()->currentProject();
    $environment = $project?->primaryEnvironment();
    $latestRun = $project?->latestRun();
    $latestWorkflow = $project?->latestActiveWorkflow();

    $navItems = [
        ['id' => 'dashboard', 'label' => __('Dashboard'), 'href' => route('dashboard'), 'disabledHint' => null],
        ['id' => 'discovery', 'label' => __('Découverte'), 'href' => route('discovery'), 'disabledHint' => null],
        ['id' => 'runs', 'label' => __("Review d'un run"), 'href' => $latestRun ? route('runs.show', $latestRun) : null, 'disabledHint' => __('Disponible après votre premier run')],
        ['id' => 'editor', 'label' => __('Éditeur'), 'href' => $latestWorkflow ? route('workflows.edit', $latestWorkflow) : null, 'disabledHint' => __('Disponible après votre premier workflow actif')],
        ['id' => 'settings-ai', 'label' => __('Réglages IA'), 'href' => route('settings-ai'), 'disabledHint' => null],
        ['id' => 'settings-environment', 'label' => __('Réglages environnement'), 'href' => route('settings-environment'), 'disabledHint' => null],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@700&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    </head>
    <body class="at-shell min-h-screen bg-at-bg font-display text-at-text antialiased">
        @if (app()->environment('production') && config('app.debug'))
            <div class="relative z-50 flex items-center justify-center gap-2 bg-verdict-broken px-4 py-1.5 text-center text-[12px] font-semibold text-at-bg">
                {{ __("APP_DEBUG est actif en production — désactivez-le dans .env avant de continuer.") }}
            </div>
        @endif

        <div class="relative min-h-screen overflow-x-hidden" x-data="{ sidebarOpen: false }">
            <div class="at-orb-violet pointer-events-none fixed -top-40 -left-30 z-0 size-[480px] animate-[at-drift_14s_ease-in-out_infinite] rounded-full"></div>
            <div class="at-orb-cyan pointer-events-none fixed -right-35 -bottom-45 z-0 size-[520px] animate-[at-drift_18s_ease-in-out_infinite_reverse] rounded-full"></div>
            <div class="at-grid-overlay pointer-events-none fixed inset-0 z-0"></div>

            <div class="relative z-10 grid min-h-screen grid-cols-1 lg:grid-cols-[236px_1fr]">
                {{-- MOBILE BACKDROP --}}
                <div
                    x-show="sidebarOpen"
                    x-transition.opacity
                    x-cloak
                    @click="sidebarOpen = false"
                    class="fixed inset-0 z-30 bg-black/60 lg:hidden"
                ></div>

                {{-- SIDEBAR --}}
                <div
                    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
                    class="fixed inset-y-0 start-0 z-40 flex w-[236px] flex-col gap-5.5 border-e border-at-border bg-at-bg p-3.5 backdrop-blur-lg transition-transform duration-200 lg:static lg:z-auto lg:bg-at-bg/70"
                >
                    <div class="flex items-center px-2 py-1">
                        <x-wordmark class="text-[16px]" />
                    </div>

                    @if ($project)
                        <div class="flex items-center justify-between px-2">
                            <div class="font-data text-xs font-medium text-at-muted-2">
                                {{ $project->slug }}@if ($environment) &middot; {{ $environment->name }} @endif
                            </div>
                            <div class="size-[7px] animate-[at-pulse-dot_2.2s_ease-in-out_infinite] rounded-full bg-verdict-pass" style="box-shadow: 0 0 6px var(--color-verdict-pass)"></div>
                        </div>
                    @endif

                    <nav class="flex flex-col gap-0.5">
                        @foreach ($navItems as $item)
                            @php $active = ($activeNav ?? null) === $item['id']; @endphp
                            <a
                                @if ($item['href']) href="{{ $item['href'] }}" wire:navigate @endif
                                @unless ($item['href']) aria-disabled="true" title="{{ $item['disabledHint'] }}" @endunless
                                class="flex items-center gap-2.5 rounded-lg border px-2.75 py-2.25 text-[13.5px] font-medium
                                    {{ $active ? 'border-at-violet/50 bg-at-violet/15 text-at-text' : 'border-transparent text-at-muted hover:bg-at-surface-3' }}
                                    {{ $item['href'] ? '' : 'pointer-events-none opacity-40' }}"
                            >
                                <span class="size-1.5 rounded-sm {{ $active ? 'bg-at-violet shadow-[0_0_6px_oklch(70%_0.18_292)]' : 'bg-at-muted/40' }}"></span>
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </nav>

                    <div class="mt-auto rounded-[11px] border border-at-violet/30 bg-linear-to-br from-at-violet/15 to-at-bg/40 p-3.5">
                        <div class="mb-1 text-[11px] font-semibold tracking-wide text-at-cyan uppercase">{{ __('Coût IA / run') }}</div>
                        <div class="font-data text-[21px] font-bold text-at-text">
                            {{ $project?->avg_ai_cost_per_run !== null ? '$'.number_format($project->avg_ai_cost_per_run, 3) : '—' }}
                        </div>
                        <div class="mt-0.5 text-[11px] text-at-muted">{{ __('Estimation — échelle d\'escalade') }}</div>
                    </div>
                </div>

                {{-- MAIN --}}
                <div class="flex min-h-screen flex-col">
                    <div class="flex h-14.5 items-center justify-between gap-2.5 border-b border-at-border bg-at-bg/60 px-4 backdrop-blur-lg lg:px-6.5">
                        <div class="flex items-center gap-2.5">
                            <button
                                type="button"
                                @click="sidebarOpen = true"
                                class="flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-md border border-at-border-2 text-at-muted-2 hover:text-at-text lg:hidden"
                            >
                                <flux:icon.bars-2 class="size-4.5" />
                            </button>
                            <div class="text-[15px] font-semibold">{{ $title ?? '' }}</div>
                        </div>
                        <div class="flex items-center gap-2.5">
                            @if ($environment?->url)
                                <div class="font-data hidden rounded-md border border-at-border px-2.75 py-1.75 text-xs text-at-muted-2 sm:block">
                                    {{ $environment->url }}
                                </div>
                            @endif
                            <a href="{{ route('discovery') }}#nl-compiler" wire:navigate class="rounded-md bg-linear-to-r from-at-violet-2 to-at-cyan-2 px-3 py-2 text-[12.5px] font-semibold text-at-bg shadow-[0_0_18px_oklch(65%_0.16_280_/_0.4)] lg:px-3.75">
                                + <span class="hidden sm:inline">{{ __('Nouveau workflow') }}</span>
                            </a>
                            <flux:dropdown position="bottom" align="end">
                                <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" />
                                <flux:menu>
                                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                                        {{ __('Settings') }}
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
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
