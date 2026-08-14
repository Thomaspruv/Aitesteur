<div class="min-w-0 flex-1">
    <flux:dropdown position="bottom" align="start" class="w-full">
        <button type="button" class="flex w-full items-center gap-1.5 truncate text-start font-data text-xs font-medium text-at-muted-2 hover:text-at-text">
            <span class="truncate">{{ $this->projects->firstWhere('id', $this->currentProjectId)?->slug ?? __('Aucun projet') }}</span>
            <flux:icon.chevron-down class="size-3 shrink-0" />
        </button>

        <flux:menu>
            @foreach ($this->projects as $project)
                <flux:menu.item wire:click="activate({{ $project->id }})" :icon="$project->id === $this->currentProjectId ? 'check' : null">
                    {{ $project->slug }}
                </flux:menu.item>
            @endforeach

            <flux:menu.separator />

            <form wire:submit="create" class="flex flex-col gap-1.5 px-2 py-1.5">
                <input
                    type="text"
                    wire:model="newProjectName"
                    placeholder="{{ __('Nouveau projet…') }}"
                    class="w-full rounded-md border border-at-border-2 bg-at-bg px-2 py-1.5 text-[12px] text-at-text-2 placeholder:text-at-muted focus:border-at-violet focus:outline-none"
                />
                @error('newProjectName')
                    <span class="text-[11px] text-verdict-broken">{{ $message }}</span>
                @enderror
                <button type="submit" class="rounded-md bg-at-surface-3 py-1.5 text-[11.5px] font-medium text-at-text hover:bg-at-surface-3/70">
                    {{ __('+ Créer') }}
                </button>
            </form>
        </flux:menu>
    </flux:dropdown>
</div>
