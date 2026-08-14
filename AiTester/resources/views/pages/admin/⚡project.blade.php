<?php

use App\Enums\AiUsageFeature;
use App\Models\DiscoveryRun;
use App\Models\Project;
use App\Models\Run;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Coûts du projet')] #[Layout('layouts::admin')] class extends Component {
    public Project $project;

    public function mount(Project $project): void
    {
        $this->project = $project;
    }

    /**
     * @return array<int, array{feature: AiUsageFeature, cost_usd: float, total_tokens: int, events: int}>
     */
    #[Computed]
    public function costByFeature(): array
    {
        return $this->project->aiUsageEvents()
            ->selectRaw('feature, SUM(cost_usd) as cost_usd, SUM(total_tokens) as total_tokens, COUNT(*) as events')
            ->groupBy('feature')
            ->get()
            ->map(fn ($row) => [
                // $row is an AiUsageEvent instance hydrated from a selectRaw()
                // query — Eloquent already applies AiUsageEvent::casts()'s
                // enum cast, so $row->feature is an AiUsageFeature instance
                // here, not a raw string; AiUsageFeature::from() would throw.
                'feature' => $row->feature,
                'cost_usd' => (float) $row->cost_usd,
                'total_tokens' => (int) $row->total_tokens,
                'events' => (int) $row->events,
            ])
            ->sortByDesc('cost_usd')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{run: Run, cost_usd: float, duration_seconds: int|null}>
     */
    #[Computed]
    public function recentRuns(): array
    {
        return Run::query()
            ->whereHas('workflow', fn ($query) => $query->where('project_id', $this->project->id))
            ->with(['workflow', 'aiUsageEvents'])
            ->latest('created_at')
            ->limit(25)
            ->get()
            ->map(fn (Run $run) => [
                'run' => $run,
                'cost_usd' => (float) $run->aiUsageEvents->sum('cost_usd'),
                'duration_seconds' => $run->started_at && $run->finished_at ? $run->started_at->diffInSeconds($run->finished_at) : null,
            ])
            ->all();
    }

    /**
     * @return array<int, array{discoveryRun: DiscoveryRun, cost_usd: float, duration_seconds: int|null}>
     */
    #[Computed]
    public function recentDiscoveryRuns(): array
    {
        return $this->project->discoveryRuns()
            ->with('aiUsageEvents')
            ->latest('created_at')
            ->limit(25)
            ->get()
            ->map(fn (DiscoveryRun $discoveryRun) => [
                'discoveryRun' => $discoveryRun,
                'cost_usd' => (float) $discoveryRun->aiUsageEvents->sum('cost_usd'),
                'duration_seconds' => $discoveryRun->started_at && $discoveryRun->finished_at ? $discoveryRun->started_at->diffInSeconds($discoveryRun->finished_at) : null,
            ])
            ->all();
    }

}; ?>

<div class="flex flex-col gap-6">
    <div>
        <a href="{{ route('admin.dashboard') }}" wire:navigate class="text-[12.5px] text-at-muted-2 hover:text-at-text">&larr; {{ __('Tous les projets') }}</a>
        <h1 class="mt-1 text-[19px] font-semibold">{{ $project->name }}</h1>
        <div class="text-[12.5px] text-at-muted-2">{{ $project->organization->name }}</div>
    </div>

    <div class="overflow-hidden rounded-[14px] border border-at-border bg-at-surface backdrop-blur-md">
        <div class="border-b border-at-border-2 px-4.5 py-3.75 text-[13px] font-semibold">{{ __('Coût par feature') }}</div>

        @forelse ($this->costByFeature as $row)
            <div class="flex items-center gap-3.5 border-b border-at-border-2 px-4.5 py-3.25 last:border-b-0">
                <div class="flex-1 text-[13.5px] font-medium">{{ $row['feature']->label() }}</div>
                <div class="w-24 text-right text-[11.5px] text-at-muted-3">{{ $row['events'] }} {{ __('appels') }}</div>
                <div class="font-data w-24 text-right text-[11.5px] text-at-muted-2">{{ number_format($row['total_tokens']) }} tok</div>
                <div class="font-data w-24 text-right text-[13.5px] font-semibold">${{ number_format($row['cost_usd'], 4) }}</div>
            </div>
        @empty
            <div class="px-4.5 py-8 text-center text-[13px] text-at-muted">{{ __('Aucune donnée de coût pour ce projet.') }}</div>
        @endforelse
    </div>

    <div class="grid grid-cols-2 items-start gap-4">
        <div class="overflow-hidden rounded-[14px] border border-at-border bg-at-surface backdrop-blur-md">
            <div class="border-b border-at-border-2 px-4.5 py-3.75 text-[13px] font-semibold">{{ __('Runs récents') }}</div>

            @forelse ($this->recentRuns as $row)
                <a href="{{ route('runs.show', $row['run']) }}" wire:navigate class="flex items-center gap-3 border-b border-at-border-2 px-4.5 py-3 last:border-b-0 hover:bg-at-surface-3">
                    <div class="flex-1 truncate text-[13px] font-medium">{{ $row['run']->workflow->name }}</div>
                    <div class="w-16 text-right text-[11.5px] text-at-muted-3">{{ $row['duration_seconds'] !== null ? $row['duration_seconds'].'s' : '—' }}</div>
                    <div class="font-data w-20 text-right text-[12.5px] font-semibold">${{ number_format($row['cost_usd'], 4) }}</div>
                </a>
            @empty
                <div class="px-4.5 py-8 text-center text-[13px] text-at-muted">{{ __('Aucun run.') }}</div>
            @endforelse
        </div>

        <div class="overflow-hidden rounded-[14px] border border-at-border bg-at-surface backdrop-blur-md">
            <div class="border-b border-at-border-2 px-4.5 py-3.75 text-[13px] font-semibold">{{ __('Discoveries récentes') }}</div>

            @forelse ($this->recentDiscoveryRuns as $row)
                <div class="flex items-center gap-3 border-b border-at-border-2 px-4.5 py-3 last:border-b-0">
                    <div class="flex-1 truncate text-[13px] font-medium">{{ $row['discoveryRun']->created_at->format('d/m/Y H:i') }}</div>
                    <div class="w-16 text-right text-[11.5px] text-at-muted-3">{{ $row['duration_seconds'] !== null ? $row['duration_seconds'].'s' : '—' }}</div>
                    <div class="font-data w-20 text-right text-[12.5px] font-semibold">${{ number_format($row['cost_usd'], 4) }}</div>
                </div>
            @empty
                <div class="px-4.5 py-8 text-center text-[13px] text-at-muted">{{ __('Aucune discovery.') }}</div>
            @endforelse
        </div>
    </div>
</div>
