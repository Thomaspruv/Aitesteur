<?php

use App\Enums\RunStatus;
use App\Enums\Verdict;
use App\Models\Run;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title("Review d'un run")] #[Layout('layouts::product', ['activeNav' => 'runs'])] class extends Component {
    public Run $run;

    public function mount(Run $run): void
    {
        $this->authorize('view', $run);

        $this->run = $run;
    }

    /**
     * @return array<int, array{n: int, label: string, reached: bool, isStop: bool}>
     */
    #[Computed]
    public function ladderLevels(): array
    {
        $labels = [0 => 'Replay', 1 => 'Self-healing', 2 => 'Re-grounding', 3 => 'Diagnostic', 4 => 'Re-dérivation'];
        $reachedLevel = $this->run->escalation_level ?? 0;

        return collect($labels)->map(fn ($label, $n) => [
            'n' => $n,
            'label' => $label,
            'reached' => $n <= $reachedLevel,
            'isStop' => $n === $reachedLevel,
        ])->values()->all();
    }
}; ?>

<div class="flex flex-col gap-4.5" @if ($run->isInProgress()) wire:poll.3s @endif>
    <div class="flex items-center justify-between rounded-[14px] border border-at-border bg-at-surface p-4.5 px-5.5 backdrop-blur-md">
        <div>
            <div class="mb-1 text-[15px] font-semibold">{{ $run->workflow->name }}</div>
            <div class="font-data text-xs text-at-muted">
                {{ __('run #:id · déclenché par :trigger · :time', ['id' => $run->id, 'trigger' => $run->triggered_by, 'time' => $run->created_at->diffForHumans()]) }}
                @if ($run->authenticated !== null)
                    · {{ $run->authenticated ? __('authentifié') : __('anonyme — identifiants non utilisés avec succès') }}
                @endif
                · <a href="{{ route('workflows.runs', $run->workflow) }}" wire:navigate class="underline">{{ __('historique') }}</a>
            </div>
        </div>
        @if ($run->verdict)
            <div class="font-data rounded-lg border px-4 py-2 text-[13px] font-bold
                {{ $run->verdict === Verdict::Broken ? 'border-verdict-broken/60 bg-verdict-broken/20 text-verdict-broken' : 'border-at-border-2 bg-at-chip text-at-text-2' }}">
                <x-verdict-badge :verdict="$run->verdict" :suffix="$run->verdict === Verdict::Broken && $run->confirmed ? __('confirmé (2e run)') : null" />
            </div>
        @elseif ($run->status === RunStatus::Failed)
            <div class="font-data rounded-lg border border-verdict-broken/60 bg-verdict-broken/20 px-4 py-2 text-[13px] font-bold text-verdict-broken">
                {{ __('Échec du run') }}
            </div>
        @else
            <div class="font-data flex items-center gap-2 rounded-lg border border-at-violet/40 bg-at-violet/10 px-4 py-2 text-[13px] font-bold text-at-violet-2">
                <span class="size-1.5 animate-pulse rounded-full bg-at-violet"></span>
                {{ $run->status === RunStatus::Running ? __('En cours…') : __("En file d'attente…") }}
            </div>
        @endif
    </div>

    @if ($run->status === RunStatus::Failed && $run->error)
        <div class="rounded-[9px] border border-verdict-broken/50 bg-verdict-broken/10 px-3.5 py-2.5 text-[12.5px] text-verdict-broken">
            {{ __('Erreur : :error', ['error' => $run->error]) }}
        </div>
    @endif

    <div class="rounded-[14px] border border-at-border bg-at-surface p-4.5 px-5.5 backdrop-blur-md">
        <div class="mb-3.5 text-[13px] font-semibold">{{ __("Échelle d'escalade") }}</div>
        <div class="flex items-center">
            @foreach ($this->ladderLevels as $level)
                <div class="flex flex-1 items-center">
                    <div class="flex-1 text-center">
                        <div @class([
                                'mx-auto mb-1.5 flex size-8.5 items-center justify-center rounded-full border-2 font-data text-xs font-bold',
                                'border-verdict-broken bg-verdict-broken/25 text-verdict-broken shadow-[0_0_14px_oklch(55%_0.2_18_/_0.7)]' => $level['isStop'] && $run->verdict === Verdict::Broken,
                                'border-at-violet bg-at-violet/20 text-at-violet-2 shadow-[0_0_14px_oklch(65%_0.16_280_/_0.5)]' => $level['isStop'] && $run->verdict !== Verdict::Broken && $level['n'] > 0,
                                'border-at-border-2 bg-at-surface-3 text-at-text-2' => $level['reached'] && ! $level['isStop'],
                                'border-at-border bg-at-bg text-at-muted' => ! $level['reached'],
                            ])>
                            L{{ $level['n'] }}
                        </div>
                        <div class="mx-auto max-w-22 text-[10.5px] text-at-muted">{{ $level['label'] }}</div>
                    </div>
                    @if (! $loop->last)
                        <div class="mb-5 h-0.5 flex-[0.6] {{ $level['reached'] ? 'bg-at-border-2' : 'bg-at-border' }}"></div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div class="rounded-[14px] border border-at-border bg-at-surface p-4.5 backdrop-blur-md">
            <div class="mb-3 text-[13px] font-semibold">{{ __('Filmstrip') }}</div>
            <div class="flex flex-col gap-2">
                @foreach ($run->runSteps as $step)
                    <div class="flex items-center gap-2.5 rounded-[9px] p-2 {{ $step->state === Verdict::Broken ? 'bg-verdict-broken/10' : '' }}">
                        @if ($step->screenshot_path)
                            <img src="{{ Storage::disk('public')->url($step->screenshot_path) }}" alt="" class="h-9 w-14 shrink-0 rounded-md border border-at-border object-cover" />
                        @else
                            <div class="at-filmstrip h-9 w-14 shrink-0 rounded-md border border-at-border"></div>
                        @endif
                        <div class="flex-1 text-[12.5px] font-medium">{{ $step->intent }}</div>
                        <x-verdict-dot :verdict="$step->state" />
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-[14px] border border-at-border bg-at-surface p-4.5 backdrop-blur-md">
            @if ($run->diagnostic_summary)
                <div class="mb-1 text-[13px] font-semibold">{{ __('Détails de l\'échec (niveau :level)', ['level' => $run->escalation_level]) }}</div>
                <div class="mb-3 text-[12px] text-at-muted">{{ __("Erreur technique remontée par l'étape en échec — aucune analyse IA n'a été effectuée sur cet échec.") }}</div>
                <div class="mb-3 grid grid-cols-2 gap-2.5">
                    <div>
                        <div class="mb-1.5 text-[10.5px] font-semibold text-at-muted">{{ __('Attendu') }}</div>
                        <div class="at-filmstrip flex h-27.5 items-center justify-center rounded-[7px] border border-at-border font-data text-[9px] text-at-muted">
                            {{ $run->expected_label ?? __('baseline') }}
                        </div>
                    </div>
                    <div>
                        <div class="mb-1.5 text-[10.5px] font-semibold text-at-muted">{{ __('Observé') }}</div>
                        <div class="at-filmstrip-error flex h-27.5 items-center justify-center rounded-[7px] border border-verdict-broken/60 font-data text-[9px] text-verdict-broken">
                            {{ $run->observed_label ?? '—' }}
                        </div>
                    </div>
                </div>
                <div class="rounded-[9px] border border-verdict-broken/50 bg-verdict-broken/15 px-3 py-2.5 text-[12px] text-at-text-2">
                    {{ __('Erreur : :summary', ['summary' => $run->diagnostic_summary]) }}
                </div>
            @elseif ($run->isInProgress())
                <div class="mb-1 text-[13px] font-semibold">{{ __('Diagnostic') }}</div>
                <div class="flex h-40 items-center justify-center text-center text-[12px] text-at-muted">
                    {{ __('Le run est en cours — le diagnostic sera disponible une fois terminé.') }}
                </div>
            @elseif ($run->status === RunStatus::Failed)
                <div class="mb-1 text-[13px] font-semibold">{{ __('Diagnostic') }}</div>
                <div class="flex h-40 items-center justify-center text-center text-[12px] text-at-muted">
                    {{ __("Le run a échoué avant de pouvoir exécuter une étape — voir le message d'erreur ci-dessus.") }}
                </div>
            @else
                <div class="mb-1 text-[13px] font-semibold">{{ __('Diagnostic') }}</div>
                <div class="flex h-40 items-center justify-center text-center text-[12px] text-at-muted">
                    {{ __("Aucune escalade nécessaire — le parcours s'est exécuté normalement.") }}
                </div>
            @endif
        </div>
    </div>
</div>
