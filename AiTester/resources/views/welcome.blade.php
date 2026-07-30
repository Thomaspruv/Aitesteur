<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name') }} — QA autonome pour SaaS B2B</title>
        <meta name="description" content="Donnez-nous l'URL de votre staging. airtight découvre seul vos parcours critiques, les surveille en continu, et ne vous alerte que si c'est vraiment cassé.">

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
            <div class="at-orb-violet pointer-events-none fixed -top-40 -left-30 z-0 size-[480px] animate-[at-drift_14s_ease-in-out_infinite] rounded-full"></div>
            <div class="at-orb-cyan pointer-events-none fixed -right-35 -bottom-45 z-0 size-[520px] animate-[at-drift_18s_ease-in-out_infinite_reverse] rounded-full"></div>
            <div class="at-grid-overlay pointer-events-none fixed inset-0 z-0"></div>

            <div class="relative z-10">
                {{-- NAV --}}
                <header class="sticky top-0 z-20 border-b border-at-border bg-at-bg/70 backdrop-blur-lg">
                    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-6">
                        <a href="{{ route('home') }}" class="flex items-center">
                            <x-wordmark class="text-[17px]" />
                        </a>

                        <nav class="hidden items-center gap-7 text-[13.5px] font-medium text-at-muted-2 md:flex">
                            <a href="#comment-ca-marche" class="hover:text-at-text">Comment ça marche</a>
                            <a href="#differenciation" class="hover:text-at-text">Positionnement</a>
                            <a href="#objectifs" class="hover:text-at-text">Objectifs</a>
                        </nav>

                        <div class="flex items-center gap-2.5">
                            @auth
                                <a href="{{ route('dashboard') }}" wire:navigate class="rounded-md border border-at-border-2 px-3.5 py-2 text-[13px] font-medium text-at-text-2 hover:bg-at-surface-3">
                                    Dashboard
                                </a>
                            @else
                                @if (Route::has('login'))
                                    <a href="{{ route('login') }}" class="rounded-md px-3.5 py-2 text-[13px] font-medium text-at-muted-2 hover:text-at-text">
                                        Se connecter
                                    </a>
                                @endif
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="rounded-md bg-linear-to-r from-at-violet-2 to-at-cyan-2 px-4 py-2 text-[13px] font-semibold text-at-bg shadow-[0_0_18px_oklch(65%_0.16_280_/_0.4)]">
                                        Créer un compte
                                    </a>
                                @endif
                            @endauth
                        </div>
                    </div>
                </header>

                {{-- HERO --}}
                <section class="mx-auto max-w-6xl px-6 pt-20 pb-24 text-center">
                    <div class="mx-auto mb-6 inline-flex items-center gap-2 rounded-full border border-at-violet/40 bg-at-violet/10 px-3.5 py-1.5 text-[11.5px] font-semibold tracking-wide text-at-violet-2 uppercase">
                        <span class="size-1.5 rounded-full bg-at-violet"></span>
                        QA autonome pour SaaS B2B
                    </div>

                    <h1 class="mx-auto max-w-3xl text-[40px] leading-[1.15] font-bold tracking-tight md:text-[52px]">
                        Donnez-nous votre URL de staging.
                        <span class="bg-linear-to-r from-at-violet-2 to-at-cyan-2 bg-clip-text text-transparent">On surveille vos parcours critiques.</span>
                    </h1>

                    <p class="mx-auto mt-6 max-w-2xl text-[16px] leading-relaxed text-at-muted-2">
                        airtight découvre seul vos parcours métier (signup, checkout, CRUD…), les transforme en tests rejouables,
                        et distingue une vraie régression d'un simple changement d'UI — sans accès à votre code source.
                    </p>

                    <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="w-full rounded-md bg-linear-to-r from-at-violet-2 to-at-cyan-2 px-6 py-3 text-[14px] font-semibold text-at-bg shadow-[0_0_20px_oklch(65%_0.16_280_/_0.45)] sm:w-auto">
                                Créer un compte gratuit
                            </a>
                        @endif
                        <a href="#comment-ca-marche" class="w-full rounded-md border border-at-border-2 px-6 py-3 text-[14px] font-medium text-at-text-2 hover:bg-at-surface-3 sm:w-auto">
                            Voir comment ça marche
                        </a>
                    </div>
                    <p class="mt-4 text-[12px] text-at-muted">Aucune carte bancaire requise. Grille tarifaire bientôt disponible.</p>

                    {{-- Decorative verdict strip --}}
                    <div class="mx-auto mt-16 flex max-w-2xl flex-wrap items-center justify-center gap-2.5">
                        <span class="rounded-full border border-at-border bg-at-surface-2 px-3.5 py-1.5"><x-verdict-badge :verdict="\App\Enums\Verdict::Pass" class="text-[11.5px]" /></span>
                        <span class="rounded-full border border-at-border bg-at-surface-2 px-3.5 py-1.5"><x-verdict-badge :verdict="\App\Enums\Verdict::PassHealed" class="text-[11.5px]" /></span>
                        <span class="rounded-full border border-at-border bg-at-surface-2 px-3.5 py-1.5"><x-verdict-badge :verdict="\App\Enums\Verdict::Changed" class="text-[11.5px]" /></span>
                        <span class="rounded-full border border-at-border bg-at-surface-2 px-3.5 py-1.5"><x-verdict-badge :verdict="\App\Enums\Verdict::Broken" class="text-[11.5px]" /></span>
                    </div>
                </section>

                {{-- PROBLEM --}}
                <section class="mx-auto max-w-6xl px-6 pb-24">
                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="rounded-[14px] border border-at-border bg-at-surface p-6 backdrop-blur-md">
                            <div class="mb-3 text-[13px] font-semibold text-verdict-broken">Sans QA dédiée</div>
                            <p class="text-[13.5px] leading-relaxed text-at-muted-2">Vous shippez vite, mais chaque déploiement est un pari — personne n'a le temps de rejouer les parcours critiques à la main.</p>
                        </div>
                        <div class="rounded-[14px] border border-at-border bg-at-surface p-6 backdrop-blur-md">
                            <div class="mb-3 text-[13px] font-semibold text-verdict-healed">Tests E2E abandonnés</div>
                            <p class="text-[13.5px] leading-relaxed text-at-muted-2">Vos tests Selenium/Playwright cassent à chaque refactoring front — pas parce que l'app est cassée, mais parce que les sélecteurs ont bougé.</p>
                        </div>
                        <div class="rounded-[14px] border border-at-border bg-at-surface p-6 backdrop-blur-md">
                            <div class="mb-3 text-[13px] font-semibold text-verdict-changed">Diffing visuel trop bruyant</div>
                            <p class="text-[13.5px] leading-relaxed text-at-muted-2">Les outils de pixel-diff alertent sur une bannière promo ou une date qui change — pas sur un vrai parcours métier cassé.</p>
                        </div>
                    </div>
                </section>

                {{-- HOW IT WORKS --}}
                <section id="comment-ca-marche" class="mx-auto max-w-6xl scroll-mt-20 px-6 pb-24">
                    <div class="mb-12 text-center">
                        <div class="mb-3 text-[12px] font-semibold tracking-wide text-at-cyan uppercase">Comment ça marche</div>
                        <h2 class="text-[30px] font-bold tracking-tight">Trois modes, un seul système</h2>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-3">
                        <div class="rounded-[14px] border border-at-border bg-at-surface p-6 backdrop-blur-md">
                            <div class="font-data mb-4 flex size-9 items-center justify-center rounded-lg bg-at-violet/15 text-[13px] font-bold text-at-violet-2">01</div>
                            <div class="mb-2 text-[15px] font-semibold">Découverte autonome</div>
                            <p class="text-[13.5px] leading-relaxed text-at-muted-2">
                                Un crawl agentique explore votre application et propose 10 à 20 parcours candidats en moins de 2 h, avec filmstrips à l'appui.
                                Vous validez ce qui est activé — rien ne part en surveillance sans votre accord.
                            </p>
                        </div>
                        <div class="rounded-[14px] border border-at-border bg-at-surface p-6 backdrop-blur-md">
                            <div class="font-data mb-4 flex size-9 items-center justify-center rounded-lg bg-at-cyan/15 text-[13px] font-bold text-at-cyan-2">02</div>
                            <div class="mb-2 text-[15px] font-semibold">Langage naturel</div>
                            <p class="text-[13.5px] leading-relaxed text-at-muted-2">
                                Décrivez un parcours en une phrase — « un utilisateur crée une facture et l'envoie » — et il est compilé en test exécutable,
                                sans appel IA à chaque exécution.
                            </p>
                        </div>
                        <div class="rounded-[14px] border border-at-border bg-at-surface p-6 backdrop-blur-md">
                            <div class="font-data mb-4 flex size-9 items-center justify-center rounded-lg bg-verdict-broken/15 text-[13px] font-bold text-verdict-broken">03</div>
                            <div class="mb-2 text-[15px] font-semibold">Détection de rupture</div>
                            <p class="text-[13.5px] leading-relaxed text-at-muted-2">
                                Une échelle d'escalade à coût croissant distingue un changement d'UI (self-healing silencieux) d'une vraie régression —
                                on ne paie l'IA que là où le déterminisme échoue.
                            </p>
                        </div>
                    </div>

                    {{-- Escalation ladder graphic --}}
                    <div class="mt-6 rounded-[14px] border border-at-border bg-at-surface p-6 px-8 backdrop-blur-md">
                        <div class="mb-5 flex items-center justify-between">
                            <div class="text-[13px] font-semibold">Échelle d'escalade</div>
                            <div class="font-data text-[11.5px] text-at-muted">chaque niveau filtre ~90 % du trafic du niveau supérieur</div>
                        </div>
                        <div class="flex items-center">
                            @foreach ([
                                ['n' => 0, 'label' => 'Replay'],
                                ['n' => 1, 'label' => 'Self-healing'],
                                ['n' => 2, 'label' => 'Re-grounding'],
                                ['n' => 3, 'label' => 'Diagnostic'],
                                ['n' => 4, 'label' => 'Re-dérivation'],
                            ] as $level)
                                <div class="flex flex-1 items-center">
                                    <div class="flex-1 text-center">
                                        <div class="font-data mx-auto mb-1.5 flex size-8.5 items-center justify-center rounded-full border-2 border-at-border-2 bg-at-surface-3 text-xs font-bold text-at-text-2">
                                            L{{ $level['n'] }}
                                        </div>
                                        <div class="mx-auto max-w-22 text-[10.5px] text-at-muted">{{ $level['label'] }}</div>
                                    </div>
                                    @if ($level['n'] < 4)
                                        <div class="mb-5 h-0.5 flex-[0.6] bg-at-border-2"></div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>

                {{-- DIFFERENTIATION --}}
                <section id="differenciation" class="mx-auto max-w-6xl scroll-mt-20 px-6 pb-24">
                    <div class="mb-12 text-center">
                        <div class="mb-3 text-[12px] font-semibold tracking-wide text-at-cyan uppercase">Positionnement</div>
                        <h2 class="text-[30px] font-bold tracking-tight">Le trou entre deux marchés</h2>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="rounded-[14px] border border-at-border bg-at-surface p-6 backdrop-blur-md">
                            <div class="mb-2 text-[13px] font-semibold text-at-muted-2">Diffing visuel cloud</div>
                            <p class="text-[13px] leading-relaxed text-at-muted">Applitools, Percy, Chromatic — excellents pour comparer des screenshots, mais il faut écrire et maintenir les tests soi-même.</p>
                        </div>
                        <div class="rounded-[14px] border border-at-border bg-at-surface p-6 backdrop-blur-md">
                            <div class="mb-2 text-[13px] font-semibold text-at-muted-2">Agents QA émergents</div>
                            <p class="text-[13px] leading-relaxed text-at-muted">Explorent l'app et suggèrent des flows, mais restent orientés review humaine intensive ou dépendants de l'accès au code source.</p>
                        </div>
                        <div class="rounded-[14px] border border-at-violet/40 bg-at-violet/10 p-6 backdrop-blur-md">
                            <div class="mb-2"><x-wordmark class="text-[13px]" /></div>
                            <p class="text-[13px] leading-relaxed text-at-text-2">La couche complète boîte noire : découverte sans code source, verdict fiable rupture/changement, coût maîtrisé par une architecture d'escalade.</p>
                        </div>
                    </div>
                </section>

                {{-- OBJECTIFS --}}
                <section id="objectifs" class="mx-auto max-w-6xl scroll-mt-20 px-6 pb-24">
                    <div class="mb-12 text-center">
                        <div class="mb-3 text-[12px] font-semibold tracking-wide text-at-cyan uppercase">Nos objectifs produit</div>
                        <h2 class="text-[30px] font-bold tracking-tight">Ce qu'on vise, honnêtement</h2>
                        <p class="mx-auto mt-3 max-w-xl text-[13.5px] text-at-muted-2">
                            Le produit est en construction. Ce sont les cibles que l'architecture est conçue pour tenir — pas encore des résultats mesurés en production.
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-xl border border-at-border bg-at-surface-2 p-5 backdrop-blur-md">
                            <div class="font-data mb-2 text-[25px] font-bold text-verdict-pass">&lt; 2%</div>
                            <div class="text-[12px] text-at-muted-2">Faux positifs visés par run</div>
                        </div>
                        <div class="rounded-xl border border-at-border bg-at-surface-2 p-5 backdrop-blur-md">
                            <div class="font-data mb-2 text-[25px] font-bold text-at-violet-2">&lt; 24h</div>
                            <div class="text-[12px] text-at-muted-2">De l'URL aux premiers parcours surveillés</div>
                        </div>
                        <div class="rounded-xl border border-at-border bg-at-surface-2 p-5 backdrop-blur-md">
                            <div class="font-data mb-2 text-[25px] font-bold text-at-cyan-2">&lt; $0.05</div>
                            <div class="text-[12px] text-at-muted-2">Coût IA visé par run de workflow</div>
                        </div>
                        <div class="rounded-xl border border-at-border bg-at-surface-2 p-5 backdrop-blur-md">
                            <div class="font-data mb-2 text-[25px] font-bold text-at-text">0</div>
                            <div class="text-[12px] text-at-muted-2">Accès à votre code source requis</div>
                        </div>
                    </div>
                </section>

                {{-- FINAL CTA --}}
                <section class="mx-auto max-w-6xl px-6 pb-24">
                    <div class="rounded-[20px] border border-at-violet/30 bg-linear-to-br from-at-violet/15 via-at-surface to-at-cyan/10 p-10 text-center backdrop-blur-md md:p-14">
                        <h2 class="mx-auto max-w-lg text-[26px] font-bold tracking-tight md:text-[30px]">Prêt à arrêter de rejouer vos parcours à la main ?</h2>
                        <p class="mx-auto mt-3 max-w-md text-[13.5px] text-at-muted-2">
                            Créez un compte gratuitement dès maintenant. La grille tarifaire arrive bientôt — en attendant, l'accès en avant-première est ouvert.
                        </p>
                        <div class="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="w-full rounded-md bg-linear-to-r from-at-violet-2 to-at-cyan-2 px-6 py-3 text-[14px] font-semibold text-at-bg shadow-[0_0_20px_oklch(65%_0.16_280_/_0.45)] sm:w-auto">
                                    Créer un compte gratuit
                                </a>
                            @endif
                            @if (Route::has('login'))
                                <a href="{{ route('login') }}" class="w-full rounded-md border border-at-border-2 px-6 py-3 text-[14px] font-medium text-at-text-2 hover:bg-at-surface-3 sm:w-auto">
                                    Se connecter
                                </a>
                            @endif
                        </div>
                    </div>
                </section>

                {{-- FOOTER --}}
                <footer class="border-t border-at-border">
                    <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-6 py-8 text-[12px] text-at-muted sm:flex-row">
                        <x-wordmark class="text-[13px] text-at-muted-2" />
                        <div>&copy; {{ date('Y') }} airtight. Tarifs bientôt disponibles.</div>
                    </div>
                </footer>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
