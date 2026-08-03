<?php

use App\Enums\Criticality;
use App\Enums\DiscoveryRunStatus;
use App\Enums\RunStatus;
use App\Enums\WorkflowOrigin;
use App\Enums\WorkflowStatus;
use App\Exceptions\AiClientException;
use App\Jobs\RunDiscoveryCrawl;
use App\Jobs\RunWorkflow;
use App\Models\Workflow;
use App\Services\Ai\WorkflowStepCompiler;
use App\Support\UrlHost;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Review de découverte')] #[Layout('layouts::product', ['activeNav' => 'discovery'])] class extends Component {
    public string $description = '';

    public string $crawlUrl = '';

    public string $crawlUsername = '';

    public string $crawlPassword = '';

    public bool $showCredentials = false;

    public bool $targetAuthorized = false;

    public string $candidateFilter = 'pending';

    public function mount(): void
    {
        $this->crawlUrl = Auth::user()->currentProject()?->primaryEnvironment()?->url ?? '';
    }

    #[Computed]
    public function project()
    {
        return Auth::user()->currentProject();
    }

    #[Computed]
    public function latestDiscoveryRun()
    {
        return $this->project?->latestDiscoveryRun();
    }

    #[Computed]
    public function candidates()
    {
        if (! $this->project) {
            return collect();
        }

        return $this->project->workflows()
            ->where('status', $this->candidateFilter === 'ignored' ? WorkflowStatus::Ignored : WorkflowStatus::Candidate)
            ->orderByDesc('score')
            ->with('latestRun')
            ->get();
    }

    #[Computed]
    public function ignoredCount(): int
    {
        return $this->project?->workflows()->where('status', WorkflowStatus::Ignored)->count() ?? 0;
    }

    #[Computed]
    public function hasStoredCredentials(): bool
    {
        return filled($this->project?->primaryEnvironment()?->username);
    }

    #[Computed]
    public function storedUsername(): ?string
    {
        return $this->project?->primaryEnvironment()?->username;
    }

    /**
     * Delegates to Environment::needsReauthorizationFor() — the project has
     * no environment yet only in the brief window before launchDiscovery()
     * creates one (see there), in which case any non-blank URL needs
     * confirmation, same as a freshly-provisioned environment would.
     */
    #[Computed]
    public function needsAuthorizationConfirmation(): bool
    {
        return $this->project?->primaryEnvironment()?->needsReauthorizationFor($this->crawlUrl)
            ?? ($this->crawlUrl !== '');
    }

    #[Computed]
    public function appGraphNodes()
    {
        return $this->project?->appGraphNodes ?? collect();
    }

    #[Computed]
    public function appGraphEdges()
    {
        return $this->project
            ? $this->project->appGraphEdges()->with(['fromNode', 'toNode'])->get()
            : collect();
    }

    public function activate(Workflow $workflow): void
    {
        $this->authorize('view', $workflow);

        $workflow->update(['status' => WorkflowStatus::Active]);

        Flux::toast(variant: 'success', text: __('Workflow activé.'));
    }

    public function ignore(Workflow $workflow): void
    {
        $this->authorize('view', $workflow);

        $workflow->update(['status' => WorkflowStatus::Ignored]);

        unset($this->ignoredCount);

        Flux::toast(text: __('Workflow ignoré.'));
    }

    public function restoreCandidate(Workflow $workflow): void
    {
        $this->authorize('view', $workflow);

        // Restored to Candidate (not Active) — it goes back to review rather
        // than being silently activated, exactly the state it was in before
        // it was ignored.
        $workflow->update(['status' => WorkflowStatus::Candidate]);

        unset($this->ignoredCount);

        Flux::toast(variant: 'success', text: __('Workflow restauré parmi les candidats.'));
    }

    public function modify(Workflow $workflow): void
    {
        $this->authorize('view', $workflow);

        $this->redirect(route('workflows.edit', $workflow), navigate: true);
    }

    public function verifyCandidate(Workflow $workflow): void
    {
        $this->authorize('view', $workflow);

        if ($workflow->latestRun?->isInProgress()) {
            Flux::toast(variant: 'warning', text: __('Une vérification est déjà en cours pour ce candidat.'));

            return;
        }

        if (! $this->project->aiClient()) {
            Flux::toast(variant: 'warning', text: __("Aucun fournisseur IA configuré — impossible de vérifier ce candidat."));

            return;
        }

        $rateLimitKey = 'run:'.Auth::id();
        if (RateLimiter::tooManyAttempts($rateLimitKey, maxAttempts: 10)) {
            Flux::toast(variant: 'warning', text: __('Trop de runs lancés récemment — réessayez dans :seconds s.', ['seconds' => RateLimiter::availableIn($rateLimitKey)]));

            return;
        }
        RateLimiter::hit($rateLimitKey, decaySeconds: 3600);

        $run = $workflow->runs()->create([
            'status' => RunStatus::Queued,
            'triggered_by' => 'manual',
        ]);

        RunWorkflow::dispatch($run);

        unset($this->candidates);

        Flux::toast(variant: 'success', text: __('Vérification lancée — ça peut prendre quelques minutes.'));
    }

    public function compile(): void
    {
        $this->authorize('view', $this->project);

        $this->validate(['description' => 'required|string|min:10|max:500']);

        $rateLimitKey = 'compile:'.Auth::id();
        if (RateLimiter::tooManyAttempts($rateLimitKey, maxAttempts: 20)) {
            Flux::toast(variant: 'warning', text: __('Trop de compilations récentes — réessayez dans :seconds s.', ['seconds' => RateLimiter::availableIn($rateLimitKey)]));

            return;
        }
        RateLimiter::hit($rateLimitKey, decaySeconds: 3600);

        $aiClient = $this->project->aiClient();

        if ($aiClient) {
            try {
                $compiled = (new WorkflowStepCompiler($aiClient))->compile($this->description);

                $this->project->workflows()->create([
                    'name' => $compiled['name'],
                    'criticality' => Criticality::from($compiled['criticality']),
                    'origin' => WorkflowOrigin::Authored,
                    'status' => WorkflowStatus::Candidate,
                    'verified' => false,
                    'steps' => $compiled['steps'],
                ]);

                $this->description = '';

                Flux::toast(variant: 'success', text: __('Test compilé par IA — en attente de review.'));

                return;
            } catch (AiClientException $exception) {
                Flux::toast(variant: 'warning', text: __('IA indisponible (:reason) — description enregistrée telle quelle.', ['reason' => $exception->getMessage()]));
            }
        }

        $this->project->workflows()->create([
            'name' => Str::limit($this->description, 60),
            'criticality' => Criticality::P1,
            'origin' => WorkflowOrigin::Authored,
            'status' => WorkflowStatus::Candidate,
            'verified' => false,
            'steps' => [
                ['intent' => $this->description, 'assertions' => []],
            ],
        ]);

        $this->description = '';

        Flux::toast(variant: 'success', text: __('Description enregistrée — en attente de compilation.'));
    }

    public function launchDiscovery(): void
    {
        $this->authorize('view', $this->project);

        if ($this->project->latestDiscoveryRun()?->isInProgress()) {
            Flux::toast(variant: 'warning', text: __('Une découverte est déjà en cours pour ce projet — attendez sa fin avant d\'en relancer une.'));

            return;
        }

        $rateLimitKey = 'discovery:'.Auth::id();
        if (RateLimiter::tooManyAttempts($rateLimitKey, maxAttempts: 5)) {
            Flux::toast(variant: 'warning', text: __('Trop de découvertes lancées récemment — réessayez dans :seconds s.', ['seconds' => RateLimiter::availableIn($rateLimitKey)]));

            return;
        }

        $this->validate([
            'crawlUrl' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                $normalized = str_starts_with($value, 'http://') || str_starts_with($value, 'https://')
                    ? $value
                    : 'https://'.$value;

                if (! filter_var($normalized, FILTER_VALIDATE_URL)) {
                    $fail(__("Cette URL n'est pas valide."));

                    return;
                }

                $host = parse_url($normalized, PHP_URL_HOST);
                $blockedHosts = array_filter([
                    parse_url((string) config('app.url'), PHP_URL_HOST),
                    request()->getHost(),
                ]);

                if ($host && collect($blockedHosts)->contains(fn ($blocked) => strcasecmp($host, $blocked) === 0)) {
                    $fail(__("Impossible de lancer une découverte sur l'application elle-même."));
                }
            }],
            'crawlUsername' => 'nullable|string|max:255',
            'crawlPassword' => 'nullable|string|max:255',
        ]);

        $needsAuthorizationConfirmation = $this->needsAuthorizationConfirmation;

        if ($needsAuthorizationConfirmation && ! $this->targetAuthorized) {
            $this->addError('targetAuthorized', __("Confirmez que vous êtes autorisé à tester ce site avant de lancer la découverte."));

            return;
        }

        RateLimiter::hit($rateLimitKey, decaySeconds: 3600);

        $environment = $this->project->primaryEnvironment()
            ?? $this->project->environments()->create(['name' => 'staging']);

        $environment->update([
            'url' => $this->crawlUrl,
            // Blank credential fields mean "keep what's already stored," not
            // "erase it" — otherwise relaunching a discovery after only
            // changing the URL would silently wipe saved credentials.
            'username' => $this->crawlUsername !== '' ? $this->crawlUsername : $environment->username,
            'password' => $this->crawlPassword !== '' ? $this->crawlPassword : $environment->password,
            'target_authorized_at' => $needsAuthorizationConfirmation ? now() : $environment->target_authorized_at,
            'target_authorized_host' => $needsAuthorizationConfirmation ? UrlHost::of($this->crawlUrl) : $environment->target_authorized_host,
        ]);

        $discoveryRun = $this->project->discoveryRuns()->create([
            'environment_id' => $environment->id,
            'status' => DiscoveryRunStatus::Queued,
        ]);

        RunDiscoveryCrawl::dispatch($discoveryRun);

        $this->crawlPassword = '';
        $this->targetAuthorized = false;
        unset($this->latestDiscoveryRun, $this->needsAuthorizationConfirmation);

        Flux::toast(variant: 'success', text: __('Découverte lancée — ça peut prendre quelques minutes.'));
    }
}; ?>

<div class="grid grid-cols-[1fr_300px] items-start gap-4">
    <div class="flex flex-col gap-3.5">
        <div class="rounded-[14px] border border-at-border bg-at-surface p-5 backdrop-blur-md">
            <div class="mb-1 text-[13px] font-semibold">{{ __('Lancer une découverte automatique') }}</div>
            <p class="mb-3.5 text-[12px] text-at-muted">
                {{ __("Donnez une URL — on explore les pages accessibles depuis là (même domaine uniquement), y compris via les liens, les popups, les menus/modales et le scroll infini ; aucun formulaire n'est soumis à part connexion/création de compte. Si vous fournissez des identifiants, on essaie de se connecter, et si ça ne marche pas on essaie de créer le compte avec ces identifiants. Plafond : 20 pages, ~3 min.") }}
            </p>

            @if ($this->latestDiscoveryRun?->isInProgress())
                <div wire:poll.3s class="mb-3.5 flex items-center gap-2.5 rounded-[9px] border border-at-violet/40 bg-at-violet/10 px-3.5 py-2.5 text-[12.5px] text-at-violet-2">
                    <span class="size-1.5 animate-pulse rounded-full bg-at-violet"></span>
                    {{ $this->latestDiscoveryRun->status === \App\Enums\DiscoveryRunStatus::Running ? __('Découverte en cours…') : __('Découverte en file d\'attente…') }}
                </div>
            @elseif ($this->latestDiscoveryRun?->status === \App\Enums\DiscoveryRunStatus::Failed)
                <div class="mb-3.5 rounded-[9px] border border-verdict-broken/50 bg-verdict-broken/10 px-3.5 py-2.5 text-[12.5px] text-verdict-broken">
                    {{ __('Échec de la dernière découverte : :error', ['error' => $this->latestDiscoveryRun->error]) }}
                </div>
            @elseif ($this->latestDiscoveryRun?->status === \App\Enums\DiscoveryRunStatus::Completed)
                <div class="mb-3.5 rounded-[9px] border border-verdict-pass/40 bg-verdict-pass/10 px-3.5 py-2.5 text-[12.5px] text-verdict-pass">
                    {{ __('Dernière découverte : :pages page(s) explorée(s), :candidates candidat(s) proposé(s).', ['pages' => $this->latestDiscoveryRun->pages_visited, 'candidates' => $this->latestDiscoveryRun->candidates_created]) }}
                    @if ($this->latestDiscoveryRun->authenticated)
                        {{ __('Connexion/création de compte réussie — le crawl a exploré des pages authentifiées.') }}
                    @elseif ($this->crawlUsername)
                        {{ __("Identifiants fournis mais non utilisés avec succès — le crawl est resté anonyme.") }}
                    @endif
                </div>
            @endif

            <form wire:submit="launchDiscovery" class="flex flex-col gap-2.5">
                <input
                    type="text"
                    wire:model="crawlUrl"
                    placeholder="https://staging.votre-app.com"
                    class="w-full rounded-[9px] border border-at-border-2 bg-at-bg px-3 py-2.5 text-[12.5px] text-at-text-2 placeholder:text-at-muted focus:border-at-violet focus:outline-none"
                />
                @error('crawlUrl')
                    <div class="text-[11.5px] text-verdict-broken">{{ $message }}</div>
                @enderror

                @if ($this->hasStoredCredentials && ! $showCredentials)
                    <div class="flex items-center justify-between rounded-[9px] border border-verdict-pass/40 bg-verdict-pass/10 px-3 py-2 text-[11.5px] text-verdict-pass">
                        <span>{{ __('Identifiants enregistrés pour ce site : :username / ••••••••', ['username' => $this->storedUsername]) }}</span>
                    </div>
                @endif

                <div class="flex items-center gap-3">
                    <button type="button" wire:click="$toggle('showCredentials')" class="self-start text-[11.5px] font-medium text-at-muted-2 hover:text-at-text">
                        @if ($showCredentials)
                            {{ __('− Masquer les identifiants') }}
                        @elseif ($this->hasStoredCredentials)
                            {{ __('Modifier les identifiants pour cette découverte') }}
                        @else
                            {{ __('+ Ajouter des identifiants pour cette découverte') }}
                        @endif
                    </button>
                    <a href="{{ route('settings-environment') }}" wire:navigate class="text-[11.5px] font-medium text-at-muted-2 underline hover:text-at-text">
                        {{ __('Configurer les valeurs par défaut') }}
                    </a>
                </div>

                @if ($showCredentials)
                    <div class="grid grid-cols-2 gap-2.5">
                        <input
                            type="text"
                            wire:model="crawlUsername"
                            placeholder="{{ $this->hasStoredCredentials ? $this->storedUsername : __('Identifiant') }}"
                            class="w-full rounded-[9px] border border-at-border-2 bg-at-bg px-3 py-2.5 text-[12.5px] text-at-text-2 placeholder:text-at-muted focus:border-at-violet focus:outline-none"
                        />
                        <input
                            type="password"
                            wire:model="crawlPassword"
                            placeholder="{{ $this->hasStoredCredentials ? __('••••••••  (laisser vide pour conserver)') : __('Mot de passe') }}"
                            class="w-full rounded-[9px] border border-at-border-2 bg-at-bg px-3 py-2.5 text-[12.5px] text-at-text-2 placeholder:text-at-muted focus:border-at-violet focus:outline-none"
                        />
                    </div>
                    <p class="text-[11px] text-at-muted">
                        {{ __('Chiffrés au repos, jamais journalisés — remplace la valeur par défaut uniquement pour cette découverte.') }}
                    </p>
                @endif

                @if ($this->needsAuthorizationConfirmation)
                    <label class="flex items-start gap-2 rounded-[9px] border border-at-border-2 bg-at-bg px-3 py-2.5 text-[12px] text-at-text-2">
                        <input type="checkbox" wire:model="targetAuthorized" class="mt-0.5" />
                        <span>{{ __("Je certifie être propriétaire de ce site, ou autorisé par son propriétaire à y lancer des explorations et tests automatisés (navigation, clics, tentatives de connexion).") }}</span>
                    </label>
                    @error('targetAuthorized')
                        <div class="text-[11.5px] text-verdict-broken">{{ $message }}</div>
                    @enderror
                @endif

                <button
                    type="submit"
                    @disabled($this->latestDiscoveryRun?->isInProgress())
                    class="mt-1 w-full cursor-pointer rounded-md bg-linear-to-r from-at-violet-2 to-at-cyan-2 py-2.25 text-center text-[12px] font-semibold text-at-bg shadow-[0_0_14px_oklch(65%_0.16_280_/_0.4)] disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ $this->latestDiscoveryRun?->isInProgress() ? __('Découverte en cours…') : __('Lancer la découverte') }}
                </button>
            </form>
        </div>

        @if ($this->latestDiscoveryRun?->status === \App\Enums\DiscoveryRunStatus::Completed)
            <div class="rounded-[14px] border border-at-border bg-at-surface p-5 backdrop-blur-md">
                <div class="mb-3.5 text-[13px] font-semibold">{{ __('Rapport de la dernière découverte') }}</div>
                <div class="grid grid-cols-3 gap-3.5">
                    <div class="rounded-xl border border-at-border bg-at-surface-2 p-4 backdrop-blur-md">
                        <div class="mb-2 text-[11px] font-medium tracking-wide text-at-muted uppercase">{{ __('Pages explorées') }}</div>
                        <div class="font-data text-[22px] font-bold text-at-text">{{ $this->latestDiscoveryRun->pages_visited ?? 0 }}</div>
                    </div>
                    <div class="rounded-xl border border-at-border bg-at-surface-2 p-4 backdrop-blur-md">
                        <div class="mb-2 text-[11px] font-medium tracking-wide text-at-muted uppercase">{{ __('Écrans avec formulaire') }}</div>
                        <div class="font-data text-[22px] font-bold text-at-text">{{ $this->latestDiscoveryRun->forms_found ?? 0 }}</div>
                    </div>
                    <div class="rounded-xl border border-at-border bg-at-surface-2 p-4 backdrop-blur-md">
                        <div class="mb-2 text-[11px] font-medium tracking-wide text-at-muted uppercase">{{ __('Workflows candidats') }}</div>
                        <div class="font-data text-[22px] font-bold text-at-text">{{ $this->latestDiscoveryRun->candidates_created ?? 0 }}</div>
                    </div>
                    <div class="rounded-xl border border-at-border bg-at-surface-2 p-4 backdrop-blur-md">
                        <div class="mb-2 text-[11px] font-medium tracking-wide text-at-muted uppercase">{{ __('Connexion détectée') }}</div>
                        <div class="font-data text-[22px] font-bold text-at-text">{{ $this->latestDiscoveryRun->login_form_detected ? __('Oui') : __('Non') }}</div>
                    </div>
                    <div class="rounded-xl border border-at-border bg-at-surface-2 p-4 backdrop-blur-md">
                        <div class="mb-2 text-[11px] font-medium tracking-wide text-at-muted uppercase">{{ __('Inscription détectée') }}</div>
                        <div class="font-data text-[22px] font-bold text-at-text">{{ $this->latestDiscoveryRun->register_form_detected ? __('Oui') : __('Non') }}</div>
                    </div>
                    <div class="rounded-xl border border-at-border bg-at-surface-2 p-4 backdrop-blur-md">
                        <div class="mb-2 text-[11px] font-medium tracking-wide text-at-muted uppercase">{{ __('Authentification réussie') }}</div>
                        <div class="font-data text-[22px] font-bold text-at-text">{{ $this->latestDiscoveryRun->authenticated ? __('Oui') : __('Non') }}</div>
                    </div>
                    <div class="rounded-xl border border-at-border bg-at-surface-2 p-4 backdrop-blur-md">
                        <div class="mb-2 text-[11px] font-medium tracking-wide text-at-muted uppercase">{{ __('Popups ouverts') }}</div>
                        <div class="font-data text-[22px] font-bold text-at-text">{{ $this->latestDiscoveryRun->popups_found ?? 0 }}</div>
                    </div>
                    <div class="rounded-xl border border-at-border bg-at-surface-2 p-4 backdrop-blur-md">
                        <div class="mb-2 text-[11px] font-medium tracking-wide text-at-muted uppercase">{{ __('Fenêtres modales') }}</div>
                        <div class="font-data text-[22px] font-bold text-at-text">{{ $this->latestDiscoveryRun->modals_found ?? 0 }}</div>
                    </div>
                </div>
            </div>
        @endif

        <div class="rounded-[14px] border border-at-border bg-at-surface p-4.5 backdrop-blur-md">
            <div class="mb-1 text-[13px] font-semibold">{{ __('Ce que la découverte a exploré') }}</div>
            <div class="mb-3 text-[11.5px] text-at-muted">
                {{ __(':nodes page(s) · :edges lien(s) suivi(s)', ['nodes' => $this->appGraphNodes->count(), 'edges' => $this->appGraphEdges->count()]) }}
            </div>
            <x-app-graph
                :nodes="$this->appGraphNodes"
                :edges="$this->appGraphEdges"
                :height="200"
                :empty-label="__('Lancez une découverte pour voir apparaître le plan du site.')"
            />
        </div>

        <div class="flex gap-1.5">
            <button
                type="button"
                wire:click="$set('candidateFilter', 'pending')"
                class="cursor-pointer rounded-md px-3 py-1.5 text-[12px] font-medium {{ $candidateFilter === 'pending' ? 'bg-at-violet/15 text-at-text' : 'text-at-muted hover:bg-at-surface-3' }}"
            >
                {{ __('En attente') }}
            </button>
            <button
                type="button"
                wire:click="$set('candidateFilter', 'ignored')"
                class="cursor-pointer rounded-md px-3 py-1.5 text-[12px] font-medium {{ $candidateFilter === 'ignored' ? 'bg-at-violet/15 text-at-text' : 'text-at-muted hover:bg-at-surface-3' }}"
            >
                {{ __('Ignorés (:count)', ['count' => $this->ignoredCount]) }}
            </button>
        </div>

        @if ($this->candidates->isEmpty())
            <div class="rounded-[14px] border border-at-border bg-at-surface p-10 text-center backdrop-blur-md">
                @if ($candidateFilter === 'ignored')
                    <div class="text-[15px] font-semibold">{{ __('Aucun candidat ignoré') }}</div>
                @else
                    <div class="text-[15px] font-semibold">{{ __('Aucun candidat pour le moment') }}</div>
                    <p class="mx-auto mt-2 max-w-md text-[13px] text-at-muted">
                        {{ __('Lancez une découverte ci-dessus, ou décrivez un parcours ci-contre pour créer votre premier candidat.') }}
                    </p>
                @endif
            </div>
        @else
            @if ($candidateFilter === 'pending')
                <div class="rounded-[11px] border border-verdict-pass/50 bg-linear-to-r from-verdict-pass/15 to-at-bg/40 px-4.5 py-3.5 text-[13px] text-verdict-pass">
                    {{ __(':count workflow(s) candidat(s) en attente de review.', ['count' => $this->candidates->count()]) }}
                </div>
            @endif

            @foreach ($this->candidates as $candidate)
                <div class="rounded-[14px] border border-at-border bg-at-surface p-5 backdrop-blur-md" wire:key="candidate-{{ $candidate->id }}">
                    <div class="mb-2.5 flex items-center justify-between">
                        <div class="flex items-center gap-2.25">
                            <div class="text-[14.5px] font-semibold">{{ $candidate->name }}</div>
                            @if ($candidate->canary)
                                <div class="font-data rounded-md border border-at-violet/60 bg-at-violet/20 px-1.75 py-0.5 text-[10px] font-bold text-at-violet-2">CANARI</div>
                            @endif
                        </div>
                        <div class="font-data text-xs font-semibold text-at-muted">
                            @if ($candidate->score !== null)
                                {{ __('score :score', ['score' => $candidate->score]) }}
                            @endif
                        </div>
                    </div>

                    <div class="mb-3.5 flex flex-wrap gap-1.5">
                        @foreach ($candidate->steps ?? [] as $step)
                            <div class="rounded-full bg-at-chip px-2.25 py-1 text-[11.5px] text-at-text-2">{{ Str::limit($step['intent'] ?? '', 42) }}</div>
                        @endforeach
                    </div>

                    <div class="mb-3.5 flex gap-1.5">
                        @foreach (range(1, 4) as $i)
                            <div class="at-filmstrip flex h-12 w-19 items-center justify-center rounded-md border border-at-border font-data text-[8px] text-at-muted">
                                {{ __('étape :n', ['n' => $i]) }}
                            </div>
                        @endforeach
                    </div>

                    <div class="flex items-center justify-between">
                        @if ($candidateFilter === 'ignored')
                            <div class="flex items-center gap-1.5 text-[11.5px] text-at-muted">
                                <span class="size-1.5 rounded-full bg-at-muted/40"></span>
                                {{ __('Ignoré') }}
                            </div>
                        @elseif ($candidate->verified)
                            <div class="flex items-center gap-1.5 text-[11.5px] text-verdict-pass">
                                <span class="size-1.5 rounded-full bg-verdict-pass"></span>
                                {{ __('Vérifié par replay') }}
                            </div>
                        @elseif ($candidate->latestRun?->isInProgress())
                            <div class="flex items-center gap-1.5 text-[11.5px] text-at-violet-2">
                                <span class="size-1.5 animate-pulse rounded-full bg-at-violet"></span>
                                {{ __('Vérification en cours…') }}
                            </div>
                        @else
                            <button type="button" wire:click="verifyCandidate({{ $candidate->id }})" class="flex cursor-pointer items-center gap-1.5 text-[11.5px] text-verdict-healed hover:underline">
                                <span class="size-1.5 rounded-full bg-verdict-healed"></span>
                                {{ __('Incertain — non rejoué · Vérifier') }}
                            </button>
                        @endif

                        @if ($candidateFilter === 'ignored')
                            <button type="button" wire:click="restoreCandidate({{ $candidate->id }})" class="cursor-pointer rounded-md bg-linear-to-r from-at-violet-2 to-at-cyan-2 px-3.5 py-1.5 text-[12px] font-semibold text-at-bg shadow-[0_0_14px_oklch(65%_0.16_280_/_0.4)]">
                                {{ __('Restaurer') }}
                            </button>
                        @else
                            <div class="flex gap-2">
                                <button type="button" wire:click="ignore({{ $candidate->id }})" wire:confirm="{{ __('Ignorer ce workflow candidat ?') }}" class="cursor-pointer rounded-md px-3 py-1.5 text-[12px] font-medium text-at-muted hover:bg-at-surface-3">
                                    {{ __('Ignorer') }}
                                </button>
                                <button type="button" wire:click="modify({{ $candidate->id }})" class="cursor-pointer rounded-md border border-at-border-2 px-3 py-1.5 text-[12px] font-medium text-at-text-2">
                                    {{ __('Modifier') }}
                                </button>
                                <button type="button" wire:click="activate({{ $candidate->id }})" class="cursor-pointer rounded-md bg-linear-to-r from-at-violet-2 to-at-cyan-2 px-3.5 py-1.5 text-[12px] font-semibold text-at-bg shadow-[0_0_14px_oklch(65%_0.16_280_/_0.4)]">
                                    {{ __('Activer') }}
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        @endif
    </div>

    <div class="flex flex-col gap-3.5">
        <div id="nl-compiler" class="scroll-mt-6 rounded-[14px] border border-at-border bg-at-surface p-4.5 backdrop-blur-md">
            <div class="mb-2.5 text-[13px] font-semibold">{{ __('Il manque un parcours ?') }}</div>
            <div class="mb-2.5 text-[12px] text-at-muted">{{ __('Décrivez-le en une phrase, on compile le test.') }}</div>

            @if (! $this->project?->aiClient())
                <div class="mb-2.5 rounded-[9px] border border-at-violet/30 bg-at-violet/10 px-3 py-2.5 text-[11.5px] text-at-violet-2">
                    {{ __('Aucun fournisseur IA configuré — la description sera enregistrée telle quelle.') }}
                    <a href="{{ route('settings-ai') }}" wire:navigate class="font-semibold underline">{{ __('Configurer dans Réglages IA') }}</a>
                </div>
            @endif

            <form wire:submit="compile">
                <textarea
                    wire:model="description"
                    rows="4"
                    placeholder="{{ __("Un utilisateur se connecte, crée une facture de 100 € pour Dupont, l'envoie…") }}"
                    class="w-full rounded-[9px] border border-at-border-2 bg-at-bg px-3 py-2.5 text-[12.5px] text-at-text-2 placeholder:text-at-muted focus:border-at-violet focus:outline-none"
                ></textarea>
                @error('description')
                    <div class="mt-1.5 text-[11.5px] text-verdict-broken">{{ $message }}</div>
                @enderror

                <button type="submit" class="mt-2.5 w-full cursor-pointer rounded-md bg-linear-to-r from-at-violet-2 to-at-cyan-2 py-2.25 text-center text-[12px] font-semibold text-at-bg shadow-[0_0_14px_oklch(65%_0.16_280_/_0.4)]">
                    {{ __('Compiler le test') }}
                </button>
            </form>
        </div>
    </div>
</div>
