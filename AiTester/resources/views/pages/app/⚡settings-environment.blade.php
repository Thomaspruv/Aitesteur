<?php

use App\Models\Environment;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Réglages environnement')] #[Layout('layouts::product', ['activeNav' => 'settings-environment'])] class extends Component {
    public Project $project;

    public Environment $environment;

    public string $url = '';

    public string $username = '';

    public string $password = '';

    public function mount(): void
    {
        $this->project = Auth::user()->currentProject();

        $this->authorize('view', $this->project);

        $this->environment = $this->project->primaryEnvironment()
            ?? $this->project->environments()->create(['name' => 'staging']);

        $this->url = $this->environment->url ?? '';
        $this->username = $this->environment->username ?? '';
    }

    public function save(): void
    {
        $this->authorize('view', $this->project);

        $this->validate([
            'url' => 'nullable|string|max:255',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
        ]);

        $this->environment->update([
            'url' => $this->url !== '' ? $this->url : null,
            // Blank fields mean "keep what's already stored," never "erase it"
            // — the password field never redisplays the decrypted value, so a
            // blank submit must not be read as "the user wants no password."
            'username' => $this->username !== '' ? $this->username : $this->environment->username,
            'password' => $this->password !== '' ? $this->password : $this->environment->password,
        ]);

        $this->password = '';

        Flux::toast(variant: 'success', text: __('Réglages environnement enregistrés.'));
    }
}; ?>

<div class="max-w-xl">
    <div class="rounded-[14px] border border-at-border bg-at-surface p-6 backdrop-blur-md">
        <div class="mb-1 text-[15px] font-semibold">{{ __('Environnement par défaut') }}</div>
        <p class="mb-5 text-[12.5px] text-at-muted">
            {{ __("L'URL et les identifiants utilisés par défaut pour la découverte automatique et l'exécution des workflows. Vous pouvez toujours saisir des identifiants différents ponctuellement depuis l'écran Découverte.") }}
        </p>

        <form wire:submit="save" class="flex flex-col gap-4">
            <div>
                <label class="mb-1.5 block text-[12px] font-medium text-at-muted-2">{{ __('URL') }}</label>
                <input
                    type="text"
                    wire:model="url"
                    placeholder="https://staging.votre-app.com"
                    class="w-full rounded-[9px] border border-at-border-2 bg-at-bg px-3 py-2.5 text-[12.5px] text-at-text-2 placeholder:text-at-muted focus:border-at-violet focus:outline-none"
                />
                @error('url')
                    <div class="mt-1.5 text-[11.5px] text-verdict-broken">{{ $message }}</div>
                @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="mb-1.5 block text-[12px] font-medium text-at-muted-2">{{ __('Identifiant') }}</label>
                    <input
                        type="text"
                        wire:model="username"
                        class="w-full rounded-[9px] border border-at-border-2 bg-at-bg px-3 py-2.5 text-[12.5px] text-at-text-2 focus:border-at-violet focus:outline-none"
                    />
                </div>
                <div>
                    <label class="mb-1.5 block text-[12px] font-medium text-at-muted-2">{{ __('Mot de passe') }}</label>
                    <input
                        type="password"
                        wire:model="password"
                        placeholder="{{ $environment->password ? __('Enregistré — laisser vide pour conserver') : '' }}"
                        class="w-full rounded-[9px] border border-at-border-2 bg-at-bg px-3 py-2.5 text-[12.5px] text-at-text-2 placeholder:text-at-muted focus:border-at-violet focus:outline-none"
                    />
                </div>
            </div>
            <p class="-mt-2 text-[11px] text-at-muted">
                {{ __('Chiffrés au repos, jamais journalisés.') }}
            </p>

            <button
                type="submit"
                class="mt-1 w-full cursor-pointer rounded-md bg-linear-to-r from-at-violet-2 to-at-cyan-2 py-2.25 text-center text-[12px] font-semibold text-at-bg shadow-[0_0_14px_oklch(65%_0.16_280_/_0.4)]"
            >
                {{ __('Enregistrer') }}
            </button>
        </form>
    </div>
</div>
