<?php

use App\Enums\RunStatus;
use App\Models\Workflow;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title("Historique des runs")] #[Layout('layouts::product', ['activeNav' => 'runs'])] class extends Component {
    use WithPagination;

    public Workflow $workflow;

    public function mount(Workflow $workflow): void
    {
        $this->authorize('view', $workflow);

        $this->workflow = $workflow;
    }

    #[Computed]
    public function runs()
    {
        return $this->workflow->runs()->latest()->paginate(20);
    }
}; ?>

<div class="flex flex-col gap-4.5">
    <div class="flex items-center justify-between rounded-[14px] border border-at-border bg-at-surface p-4.5 px-5.5 backdrop-blur-md">
        <div>
            <div class="mb-1 text-[15px] font-semibold">{{ __('Historique — :name', ['name' => $workflow->name]) }}</div>
            <div class="font-data text-xs text-at-muted">
                {{ __(':count run(s)', ['count' => $this->runs->total()]) }}
            </div>
        </div>
        <a href="{{ route('workflows.edit', $workflow) }}" wire:navigate class="rounded-md border border-at-border-2 px-3 py-1.5 text-[12px] font-medium text-at-text-2">
            {{ __("Retour à l'éditeur") }}
        </a>
    </div>

    <div class="overflow-hidden rounded-[14px] border border-at-border bg-at-surface backdrop-blur-md">
        @forelse ($this->runs as $run)
            <a
                href="{{ route('runs.show', $run) }}"
                wire:navigate
                wire:key="run-{{ $run->id }}"
                class="flex items-center gap-3.5 border-b border-at-border-2 px-4.5 py-3.25 last:border-b-0 hover:bg-at-surface-3"
            >
                @if ($run->verdict)
                    <x-verdict-dot :verdict="$run->verdict" />
                @elseif ($run->status === RunStatus::Failed)
                    <span class="size-[7px] shrink-0 rounded-full bg-verdict-broken"></span>
                @else
                    <span class="size-[7px] shrink-0 animate-pulse rounded-full bg-at-violet"></span>
                @endif

                <div class="flex-1 text-[13.5px] font-medium">
                    {{ __('run #:id', ['id' => $run->id]) }}
                </div>

                <div class="font-data rounded-md bg-at-chip px-1.75 py-0.5 text-[10.5px] font-bold text-at-muted-2">
                    {{ __(':trigger', ['trigger' => $run->triggered_by]) }}
                </div>

                <div class="w-28 text-[11.5px] font-semibold">
                    @if ($run->verdict)
                        <x-verdict-badge :verdict="$run->verdict" />
                    @elseif ($run->status === RunStatus::Failed)
                        <span class="text-verdict-broken">{{ __('Échec') }}</span>
                    @else
                        <span class="text-at-violet-2">{{ $run->status === RunStatus::Running ? __('En cours…') : __("En file d'attente…") }}</span>
                    @endif
                </div>

                <div class="w-22 text-right text-[11.5px] text-at-muted-3">
                    {{ $run->created_at->diffForHumans() }}
                </div>
            </a>
        @empty
            <div class="p-10 text-center text-[13px] text-at-muted">
                {{ __('Aucun run pour le moment.') }}
            </div>
        @endforelse
    </div>

    @if ($this->runs->hasPages())
        <flux:pagination :paginator="$this->runs" />
    @endif
</div>
