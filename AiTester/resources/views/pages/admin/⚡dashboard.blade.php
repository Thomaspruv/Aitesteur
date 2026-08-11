<?php

use App\Models\AiUsageEvent;
use App\Models\DiscoveryRun;
use App\Models\Project;
use App\Models\Run;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Coûts IA')] #[Layout('layouts::admin')] class extends Component {
    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->from = now()->subDays(30)->toDateString();
        $this->to = now()->toDateString();
    }

    protected function period(): array
    {
        return [Carbon::parse($this->from)->startOfDay(), Carbon::parse($this->to)->endOfDay()];
    }

    /**
     * @return array<int, array{project: Project, cost_usd: float, total_tokens: int, events: int, runs: int, discoveries: int}>
     */
    #[Computed]
    public function rows(): array
    {
        [$from, $to] = $this->period();

        $usageByProject = AiUsageEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('project_id, SUM(cost_usd) as cost_usd, SUM(total_tokens) as total_tokens, COUNT(*) as events')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        $runCounts = Run::query()
            ->join('workflows', 'workflows.id', '=', 'runs.workflow_id')
            ->whereBetween('runs.created_at', [$from, $to])
            ->selectRaw('workflows.project_id as project_id, COUNT(*) as count')
            ->groupBy('workflows.project_id')
            ->pluck('count', 'project_id');

        $discoveryCounts = DiscoveryRun::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('project_id, COUNT(*) as count')
            ->groupBy('project_id')
            ->pluck('count', 'project_id');

        return Project::query()
            ->with('organization')
            ->get()
            ->map(function (Project $project) use ($usageByProject, $runCounts, $discoveryCounts) {
                $usage = $usageByProject->get($project->id);

                return [
                    'project' => $project,
                    'cost_usd' => (float) ($usage->cost_usd ?? 0),
                    'total_tokens' => (int) ($usage->total_tokens ?? 0),
                    'events' => (int) ($usage->events ?? 0),
                    'runs' => (int) ($runCounts[$project->id] ?? 0),
                    'discoveries' => (int) ($discoveryCounts[$project->id] ?? 0),
                ];
            })
            ->sortByDesc('cost_usd')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    #[Computed]
    public function stats(): array
    {
        $totalCost = collect($this->rows)->sum('cost_usd');
        $totalTokens = collect($this->rows)->sum('total_tokens');
        $totalRuns = collect($this->rows)->sum('runs');
        $totalDiscoveries = collect($this->rows)->sum('discoveries');

        return [
            ['label' => 'Coût IA total', 'value' => '$'.number_format($totalCost, 4)],
            ['label' => 'Tokens consommés', 'value' => number_format($totalTokens)],
            ['label' => 'Runs', 'value' => (string) $totalRuns],
            ['label' => 'Discoveries', 'value' => (string) $totalDiscoveries],
        ];
    }

}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="text-[13px] text-at-muted">
            {{ __('Coût IA (tokens réels facturés par DeepSeek) par projet — la découverte et les runs antérieurs à la mise en place de ce suivi n\'apparaissent pas.') }}
        </div>
        <div class="flex items-end gap-3">
            <flux:input type="date" wire:model.live="from" :label="__('Du')" />
            <flux:input type="date" wire:model.live="to" :label="__('Au')" />
        </div>
    </div>

    <div class="grid grid-cols-4 gap-3.5">
        @foreach ($this->stats as $stat)
            <div class="rounded-xl border border-at-border bg-at-surface-2 p-4 px-4.5 backdrop-blur-md">
                <div class="mb-2 text-[11.5px] font-medium tracking-wide text-at-muted uppercase">{{ $stat['label'] }}</div>
                <div class="font-data text-[25px] font-bold text-at-text">{{ $stat['value'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-[14px] border border-at-border bg-at-surface backdrop-blur-md">
        <div class="flex items-center justify-between border-b border-at-border-2 px-4.5 py-3.75 text-[13px] font-semibold">
            <div>{{ __('Coût par projet') }}</div>
            <div class="font-data text-xs font-medium text-at-muted">{{ __(':count projets', ['count' => count($this->rows)]) }}</div>
        </div>

        @forelse ($this->rows as $row)
            <a
                href="{{ route('admin.project', $row['project']) }}"
                wire:navigate
                class="flex items-center gap-3.5 border-b border-at-border-2 px-4.5 py-3.25 last:border-b-0 hover:bg-at-surface-3"
            >
                <div class="flex-1">
                    <div class="text-[13.5px] font-medium">{{ $row['project']->name }}</div>
                    <div class="text-[11.5px] text-at-muted-2">{{ $row['project']->organization->name }}</div>
                </div>
                <div class="w-20 text-right text-[11.5px] text-at-muted-3">{{ $row['runs'] }} {{ __('runs') }}</div>
                <div class="w-24 text-right text-[11.5px] text-at-muted-3">{{ $row['discoveries'] }} {{ __('discoveries') }}</div>
                <div class="font-data w-24 text-right text-[11.5px] text-at-muted-2">{{ number_format($row['total_tokens']) }} tok</div>
                <div class="font-data w-24 text-right text-[13.5px] font-semibold">${{ number_format($row['cost_usd'], 4) }}</div>
            </a>
        @empty
            <div class="px-4.5 py-8 text-center text-[13px] text-at-muted">{{ __('Aucun projet.') }}</div>
        @endforelse
    </div>
</div>
