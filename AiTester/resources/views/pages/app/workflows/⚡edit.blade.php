<?php

use App\Enums\RunStatus;
use App\Enums\WorkflowStatus;
use App\Jobs\RunWorkflow;
use App\Models\Workflow;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Éditeur de workflow')] #[Layout('layouts::product', ['activeNav' => 'editor'])] class extends Component {
    public Workflow $workflow;

    /**
     * @var array<int, array{intent: string, assertions: array<int, array{label: string, on: bool}>}>
     */
    public array $steps = [];

    public function mount(Workflow $workflow): void
    {
        $this->authorize('view', $workflow);

        $this->workflow = $workflow;
        $this->steps = $workflow->steps ?? [];
    }

    #[Computed]
    public function versions()
    {
        return $this->workflow->workflowVersions()->orderByDesc('version')->get();
    }

    #[Computed]
    public function currentVersion(): int
    {
        return (int) ($this->workflow->workflowVersions()->max('version') ?? 1);
    }

    #[Computed]
    public function latestRun()
    {
        return $this->workflow->latestRun()->first();
    }

    public function launchRun(): void
    {
        $this->authorize('view', $this->workflow);

        if ($this->latestRun?->isInProgress()) {
            Flux::toast(variant: 'warning', text: __('Un run est déjà en cours pour ce workflow — attendez sa fin avant d\'en relancer un.'));

            return;
        }

        if (! $this->workflow->project->aiClient()) {
            Flux::toast(variant: 'warning', text: __("Aucun fournisseur IA configuré — impossible d'exécuter un run."));

            return;
        }

        $rateLimitKey = 'run:'.Auth::id();
        if (RateLimiter::tooManyAttempts($rateLimitKey, maxAttempts: 10)) {
            Flux::toast(variant: 'warning', text: __('Trop de runs lancés récemment — réessayez dans :seconds s.', ['seconds' => RateLimiter::availableIn($rateLimitKey)]));

            return;
        }
        RateLimiter::hit($rateLimitKey, decaySeconds: 3600);

        $run = $this->workflow->runs()->create([
            'status' => RunStatus::Queued,
            'triggered_by' => 'manual',
        ]);

        RunWorkflow::dispatch($run);

        unset($this->latestRun);

        Flux::toast(variant: 'success', text: __('Run lancé — ça peut prendre quelques minutes.'));
    }

    public function activate(): void
    {
        $this->authorize('view', $this->workflow);

        $this->workflow->update(['status' => WorkflowStatus::Active]);

        Flux::toast(variant: 'success', text: __('Workflow activé.'));
    }

    public function ignore(): void
    {
        $this->authorize('view', $this->workflow);

        $this->workflow->update(['status' => WorkflowStatus::Ignored]);

        Flux::toast(text: __('Workflow ignoré.'));
    }

    public function saveIntent(int $index): void
    {
        $this->authorize('view', $this->workflow);

        $before = $this->workflow->steps[$index]['intent'] ?? null;
        $after = trim($this->steps[$index]['intent'] ?? '');

        if ($after === '') {
            // Revert instead of persisting a blank step — an empty intent
            // has nothing for the execution engine to act on, and would
            // otherwise waste a real AI call with a confusing diagnostic.
            $this->steps[$index]['intent'] = $before;
            Flux::toast(variant: 'warning', text: __("L'intention d'une étape ne peut pas être vide."));

            return;
        }

        $this->steps[$index]['intent'] = $after;

        if ($before === $after) {
            return;
        }

        $this->persist(__('Intention modifiée (étape :n)', ['n' => $index + 1]));
    }

    public function toggleAssertion(int $stepIndex, int $assertionIndex): void
    {
        $this->authorize('view', $this->workflow);

        $current = $this->steps[$stepIndex]['assertions'][$assertionIndex]['on'] ?? false;
        $this->steps[$stepIndex]['assertions'][$assertionIndex]['on'] = ! $current;

        $label = $this->steps[$stepIndex]['assertions'][$assertionIndex]['label'] ?? '';

        $this->persist(__(':state « :label » (étape :n)', [
            'state' => $current ? 'Assertion désactivée' : 'Assertion activée',
            'label' => $label,
            'n' => $stepIndex + 1,
        ]));
    }

    protected function persist(string $changeLabel): void
    {
        $this->workflow->steps = $this->steps;
        $this->workflow->saveStepsAsNewVersion($changeLabel);

        unset($this->versions, $this->currentVersion);
    }
}; ?>

<div class="grid grid-cols-[1fr_260px] items-start gap-4">
    <div class="rounded-[14px] border border-at-border bg-at-surface p-5.5 backdrop-blur-md">
        <div class="mb-4 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <div class="text-[15px] font-semibold">{{ $workflow->name }}</div>
                <div class="font-data rounded-md bg-at-chip px-2.25 py-1 text-[11px] font-semibold text-at-muted-2">v{{ $this->currentVersion }}</div>
            </div>
            <div class="flex items-center gap-2.5">
                @if ($workflow->status === WorkflowStatus::Active)
                    <span class="font-data text-[11px] font-semibold text-verdict-pass">{{ __('Actif') }}</span>
                    <button type="button" wire:click="ignore" wire:confirm="{{ __('Désactiver ce workflow ?') }}" class="cursor-pointer rounded-md border border-at-border-2 px-3 py-1.5 text-[12px] font-medium text-at-text-2">
                        {{ __('Désactiver') }}
                    </button>
                @elseif ($workflow->status === WorkflowStatus::Candidate)
                    <span class="font-data text-[11px] font-semibold text-verdict-healed">{{ __('Candidat') }}</span>
                    <button type="button" wire:click="activate" class="cursor-pointer rounded-md bg-linear-to-r from-at-violet-2 to-at-cyan-2 px-3.5 py-1.5 text-[12px] font-semibold text-at-bg shadow-[0_0_14px_oklch(65%_0.16_280_/_0.4)]">
                        {{ __('Activer') }}
                    </button>
                @else
                    <span class="font-data text-[11px] font-semibold text-at-muted">{{ __('Ignoré') }}</span>
                    <button type="button" wire:click="activate" class="cursor-pointer rounded-md border border-at-border-2 px-3 py-1.5 text-[12px] font-medium text-at-text-2">
                        {{ __('Réactiver') }}
                    </button>
                @endif

                <button
                    type="button"
                    wire:click="launchRun"
                    @disabled($this->latestRun?->isInProgress())
                    class="cursor-pointer rounded-md bg-linear-to-r from-at-violet-2 to-at-cyan-2 px-3.5 py-1.5 text-[12px] font-semibold text-at-bg shadow-[0_0_14px_oklch(65%_0.16_280_/_0.4)] disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ $this->latestRun?->isInProgress() ? __('Run en cours…') : __('Lancer un run') }}
                </button>
            </div>
        </div>

        <p class="mb-3.5 text-[12px] text-at-muted">
            {{ __('Ces étapes sont rejouées par un agent IA sur votre site à chaque run — cliquer sur "Lancer un run" ouvre un vrai navigateur, exécute chaque étape, et enregistre le résultat.') }}
        </p>

        @if (! $this->workflow->project->aiClient())
            <div class="mb-3.5 rounded-[9px] border border-at-violet/30 bg-at-violet/10 px-3 py-2.5 text-[11.5px] text-at-violet-2">
                {{ __('Aucun fournisseur IA configuré — un run ne peut pas être exécuté sans IA.') }}
                <a href="{{ route('settings-ai') }}" wire:navigate class="font-semibold underline">{{ __('Configurer dans Réglages IA') }}</a>
            </div>
        @elseif ($this->latestRun?->isInProgress())
            <div wire:poll.3s class="mb-3.5 flex items-center gap-2.5 rounded-[9px] border border-at-violet/40 bg-at-violet/10 px-3.5 py-2.5 text-[12.5px] text-at-violet-2">
                <span class="size-1.5 animate-pulse rounded-full bg-at-violet"></span>
                {{ $this->latestRun->status === RunStatus::Running ? __('Run en cours…') : __('Run en file d\'attente…') }}
            </div>
        @elseif ($this->latestRun?->status === RunStatus::Failed)
            <div class="mb-3.5 rounded-[9px] border border-verdict-broken/50 bg-verdict-broken/10 px-3.5 py-2.5 text-[12.5px] text-verdict-broken">
                {{ __('Échec du dernier run : :error', ['error' => $this->latestRun->error]) }}
            </div>
        @elseif ($this->latestRun?->status === RunStatus::Completed)
            <div class="mb-3.5 rounded-[9px] border border-at-border-2 bg-at-chip px-3.5 py-2.5 text-[12.5px] text-at-text-2">
                {{ __('Dernier run : ') }}
                <a href="{{ route('runs.show', $this->latestRun) }}" wire:navigate class="font-semibold underline">
                    <x-verdict-badge :verdict="$this->latestRun->verdict" />
                </a>
            </div>
        @endif

        <div class="flex flex-col gap-2.5">
            @foreach ($steps as $i => $step)
                <div class="flex items-start gap-3 rounded-[10px] border border-at-border-2 p-3.5" wire:key="step-{{ $i }}">
                    <div class="font-data mt-0.5 flex size-5.5 shrink-0 items-center justify-center rounded-full bg-at-chip text-[11px] font-bold text-at-muted-2">
                        {{ $i + 1 }}
                    </div>
                    <div class="flex-1">
                        <input
                            type="text"
                            wire:model="steps.{{ $i }}.intent"
                            wire:blur="saveIntent({{ $i }})"
                            class="w-full rounded-md border border-transparent bg-transparent py-1.5 text-[13.5px] text-at-text-2 hover:border-at-border-2 focus:border-at-violet focus:bg-at-bg focus:outline-none"
                        />
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            @foreach ($step['assertions'] ?? [] as $j => $assertion)
                                <button
                                    type="button"
                                    wire:click="toggleAssertion({{ $i }}, {{ $j }})"
                                    class="flex cursor-pointer items-center gap-1.5 rounded-full bg-at-surface-3 px-2 py-1 text-[11px] text-at-muted"
                                >
                                    <span class="relative h-3 w-5.5 rounded-full {{ $assertion['on'] ? 'bg-verdict-pass' : 'bg-at-border' }}">
                                        <span class="absolute top-0.5 size-2.25 rounded-full bg-at-text transition-all {{ $assertion['on'] ? 'left-2.75' : 'left-0.5' }}"></span>
                                    </span>
                                    {{ $assertion['label'] }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="rounded-[14px] border border-at-border bg-at-surface p-4.5 backdrop-blur-md">
        <div class="mb-3 text-[13px] font-semibold">{{ __('Historique des versions') }}</div>
        <div class="flex flex-col gap-2.5">
            @forelse ($this->versions as $version)
                <div class="flex items-start gap-2.5">
                    <span class="mt-1.25 size-1.5 shrink-0 rounded-full bg-at-muted/50"></span>
                    <div>
                        <div class="font-data text-[11.5px] font-semibold text-at-text-2">v{{ $version->version }}</div>
                        <div class="text-[12px] text-at-muted-2">{{ $version->change_label }}</div>
                        <div class="text-[11px] text-at-muted-3">{{ $version->created_at->format('d M.') }}</div>
                    </div>
                </div>
            @empty
                <div class="text-[12px] text-at-muted">{{ __('Aucune version enregistrée pour le moment.') }}</div>
            @endforelse
        </div>

        <a href="{{ route('workflows.runs', $workflow) }}" wire:navigate class="mt-4 block rounded-md border border-at-border-2 px-3 py-1.5 text-center text-[12px] font-medium text-at-text-2 hover:bg-at-surface-3">
            {{ __("Historique des runs") }}
        </a>
    </div>
</div>
