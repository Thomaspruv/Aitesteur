<?php

use App\Enums\Verdict;
use App\Enums\WorkflowStatus;
use App\Models\Run;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard projet')] #[Layout('layouts::product', ['activeNav' => 'dashboard'])] class extends Component {
    #[Computed]
    public function project()
    {
        return Auth::user()->currentProject();
    }

    #[Computed]
    public function workflows()
    {
        if (! $this->project) {
            return collect();
        }

        $order = ['P0' => 0, 'P1' => 1, 'P2' => 2];

        return $this->project->workflows()
            ->where('status', WorkflowStatus::Active)
            ->with('latestRun')
            ->get()
            ->sortBy(fn ($workflow) => $order[$workflow->criticality->value] ?? 9)
            ->values();
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    #[Computed]
    public function stats(): array
    {
        $project = $this->project;

        if (! $project) {
            return [];
        }

        $runs = Run::query()
            ->whereIn('workflow_id', $this->workflows->pluck('id'))
            ->where('created_at', '>=', now()->subDays(30))
            ->get();

        $falsePositives = $runs->where('verdict', Verdict::Broken)->where('confirmed', false)->count();
        $falsePositiveRate = $runs->isEmpty() ? null : round($falsePositives / $runs->count() * 100, 1);

        return [
            ['label' => 'Workflows surveillés', 'value' => (string) $this->workflows->count()],
            ['label' => 'Faux positifs (30j)', 'value' => $falsePositiveRate === null ? '—' : $falsePositiveRate.'%'],
            ['label' => 'Coût IA / run', 'value' => $project->avg_ai_cost_per_run !== null ? '$'.number_format($project->avg_ai_cost_per_run, 3) : '—'],
            ['label' => 'Disponibilité', 'value' => $project->uptime_pct !== null ? number_format($project->uptime_pct, 1).'%' : '—'],
        ];
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

    /**
     * Normalizes values into the sparkline's viewBox height (0-20, with
     * padding) rather than plotting them as raw y-coordinates — spark_data
     * can hold anything from small demo integers to real run durations in
     * seconds, and a value plotted outside 0-20 is silently clipped by the
     * SVG's default overflow:hidden.
     *
     * @param  array<int, int>  $data
     */
    public function sparklinePoints(array $data): string
    {
        $values = collect($data)->values();

        if ($values->isEmpty()) {
            return '';
        }

        $min = $values->min();
        $range = $values->max() - $min;

        return $values
            ->map(function ($value, $i) use ($min, $range) {
                $normalized = $range > 0 ? ($value - $min) / $range : 0.5;
                $y = 18 - ($normalized * 16);

                return ($i * 8).','.round($y, 1);
            })
            ->implode(' ');
    }

}; ?>

<div class="flex flex-col gap-6">
        @if ($this->workflows->isEmpty())
            <div class="rounded-[14px] border border-at-border bg-at-surface p-10 text-center backdrop-blur-md">
                <div class="text-[15px] font-semibold">{{ __('Aucun workflow surveillé pour le moment') }}</div>
                <p class="mx-auto mt-2 max-w-md text-[13px] text-at-muted">
                    {{ __('Activez des workflows découverts ou décrivez-en un en langage naturel pour commencer la surveillance.') }}
                </p>
                <a href="{{ route('discovery') }}" wire:navigate class="mt-5 inline-block rounded-md bg-linear-to-r from-at-violet-2 to-at-cyan-2 px-4 py-2 text-[12.5px] font-semibold text-at-bg shadow-[0_0_18px_oklch(65%_0.16_280_/_0.4)]">
                    {{ __('Aller à la découverte') }}
                </a>
            </div>
        @else
            <div class="grid grid-cols-4 gap-3.5">
                @foreach ($this->stats as $stat)
                    <div class="rounded-xl border border-at-border bg-at-surface-2 p-4 px-4.5 backdrop-blur-md">
                        <div class="mb-2 text-[11.5px] font-medium tracking-wide text-at-muted uppercase">{{ $stat['label'] }}</div>
                        <div class="font-data text-[25px] font-bold text-at-text">{{ $stat['value'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-[1.6fr_1fr] items-start gap-4">
                <div class="overflow-hidden rounded-[14px] border border-at-border bg-at-surface backdrop-blur-md">
                    <div class="flex items-center justify-between border-b border-at-border-2 px-4.5 py-3.75 text-[13px] font-semibold">
                        <div>{{ __('Workflows surveillés') }}</div>
                        <div class="font-data text-xs font-medium text-at-muted">{{ __(':count actifs', ['count' => $this->workflows->count()]) }}</div>
                    </div>
                    @foreach ($this->workflows as $workflow)
                        @php $latestRun = $workflow->latestRun; @endphp
                        <a
                            @if ($latestRun) href="{{ route('runs.show', $latestRun) }}" wire:navigate @endif
                            class="flex items-center gap-3.5 border-b border-at-border-2 px-4.5 py-3.25 last:border-b-0 {{ $latestRun ? 'cursor-pointer hover:bg-at-surface-3' : 'cursor-default opacity-70' }}"
                        >
                            @if ($workflow->latest_verdict)
                                <x-verdict-dot :verdict="$workflow->latest_verdict" />
                            @else
                                <span class="size-[7px] shrink-0 rounded-full bg-at-muted/40"></span>
                            @endif
                            <div class="flex-1 text-[13.5px] font-medium">{{ $workflow->name }}</div>
                            <div class="font-data rounded-md bg-at-chip px-1.75 py-0.5 text-[10.5px] font-bold text-at-muted-2">{{ $workflow->criticality->value }}</div>
                            <svg width="64" height="20" viewBox="0 0 64 20" class="shrink-0">
                                <polyline
                                    points="{{ $this->sparklinePoints($workflow->spark_data ?? []) }}"
                                    fill="none"
                                    stroke="{{ $workflow->latest_verdict ? 'var(--color-verdict-'.$workflow->latest_verdict->slug().')' : 'var(--color-at-muted)' }}"
                                    stroke-width="1.6"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                ></polyline>
                            </svg>
                            <div class="w-24 text-[11.5px] font-semibold">
                                @if ($workflow->latest_verdict)
                                    <x-verdict-badge :verdict="$workflow->latest_verdict" />
                                @else
                                    <span class="text-at-muted">—</span>
                                @endif
                            </div>
                            <div class="w-22 text-right text-[11.5px] text-at-muted-3">
                                {{ $workflow->last_run_at?->diffForHumans() ?? __('jamais') }}
                            </div>
                        </a>
                    @endforeach
                </div>

                <div class="rounded-[14px] border border-at-border bg-at-surface p-4.5 backdrop-blur-md">
                    <div class="mb-1 text-[13px] font-semibold">{{ __('App Graph') }}</div>
                    <div class="mb-3 text-[11.5px] text-at-muted">
                        {{ __(':nodes états · :edges actions observées', ['nodes' => $this->appGraphNodes->count(), 'edges' => $this->appGraphEdges->count()]) }}
                    </div>
                    <x-app-graph :nodes="$this->appGraphNodes" :edges="$this->appGraphEdges" :height="180" />
                </div>
            </div>
    @endif
</div>
