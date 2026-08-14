<div>
    <flux:dropdown position="bottom" align="start" class="w-full">
        <button type="button" class="flex w-full items-center gap-1.5 truncate text-start font-data text-xs font-medium text-at-muted-2 hover:text-at-text">
            <span class="truncate">{{ $this->environments->firstWhere('id', $this->activeEnvironmentId)?->name ?? __('Aucun site') }}</span>
            <flux:icon.chevron-down class="size-3 shrink-0" />
        </button>

        <flux:menu>
            @forelse ($this->environments as $environment)
                <flux:menu.item wire:click="activate({{ $environment->id }})" :icon="$environment->id === $this->activeEnvironmentId ? 'check' : null">
                    {{ $environment->name }}
                </flux:menu.item>
            @empty
                <div class="px-2 py-1.5 text-[11.5px] text-at-muted">{{ __('Aucun site pour ce projet.') }}</div>
            @endforelse

            <flux:menu.separator />

            <flux:menu.item :href="route('settings-environment')" icon="plus" wire:navigate>
                {{ __('Nouveau site') }}
            </flux:menu.item>
        </flux:menu>
    </flux:dropdown>
</div>
