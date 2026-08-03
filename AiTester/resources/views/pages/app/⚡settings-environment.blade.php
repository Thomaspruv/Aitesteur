<?php

use App\Models\Environment;
use App\Models\Project;
use App\Support\UrlHost;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Réglages environnement')] #[Layout('layouts::product', ['activeNav' => 'settings-environment'])] class extends Component {
    public Project $project;

    public Environment $environment;

    public string $url = '';

    public string $username = '';

    public string $password = '';

    public bool $targetAuthorized = false;

    public function mount(): void
    {
        $this->project = Auth::user()->currentProject();

        $this->authorize('view', $this->project);

        $this->environment = $this->project->primaryEnvironment()
            ?? $this->project->environments()->create(['name' => 'staging']);

        $this->url = $this->environment->url ?? '';
        $this->username = $this->environment->username ?? '';
    }

    #[Computed]
    public function needsAuthorizationConfirmation(): bool
    {
        return $this->environment->needsReauthorizationFor($this->url);
    }

    public function save(): void
    {
        $this->authorize('view', $this->project);

        $this->validate([
            // discovery.blade.php's launchDiscovery() already refuses to crawl the
            // app's own host — this is the same check for the other place an
            // environment's url can be set, closed by a UrlHost::of() comparison
            // (host-only, case-insensitive) rather than raw string equality.
            'url' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail) {
                if ($value === '') {
                    return;
                }

                $host = UrlHost::of($value);
                $blockedHosts = array_filter([
                    UrlHost::of((string) config('app.url')),
                    UrlHost::of(request()->getHost()),
                ]);

                if ($host && in_array($host, $blockedHosts, true)) {
                    $fail(__("Impossible de cibler l'application elle-même."));
                }
            }],
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
        ]);

        if ($this->needsAuthorizationConfirmation && ! $this->targetAuthorized) {
            $this->addError('targetAuthorized', __("Confirmez que vous êtes autorisé à tester ce site avant d'enregistrer."));

            return;
        }

        $this->environment->update([
            'url' => $this->url !== '' ? $this->url : null,
            // Blank fields mean "keep what's already stored," never "erase it"
            // — the password field never redisplays the decrypted value, so a
            // blank submit must not be read as "the user wants no password."
            'username' => $this->username !== '' ? $this->username : $this->environment->username,
            'password' => $this->password !== '' ? $this->password : $this->environment->password,
            'target_authorized_at' => $this->needsAuthorizationConfirmation ? now() : $this->environment->target_authorized_at,
            'target_authorized_host' => $this->needsAuthorizationConfirmation ? UrlHost::of($this->url) : $this->environment->target_authorized_host,
        ]);

        $this->password = '';
        $this->targetAuthorized = false;
        unset($this->needsAuthorizationConfirmation);

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

            @if ($this->needsAuthorizationConfirmation)
                <label class="flex items-start gap-2 rounded-[9px] border border-at-border-2 bg-at-bg px-3 py-2.5 text-[12px] text-at-text-2">
                    <input type="checkbox" wire:model="targetAuthorized" class="mt-0.5" />
                    <span>{{ __("Je certifie être propriétaire de ce site, ou autorisé par son propriétaire à y lancer des explorations et tests automatisés (navigation, clics, tentatives de connexion).") }}</span>
                </label>
                @error('targetAuthorized')
                    <div class="-mt-2 text-[11.5px] text-verdict-broken">{{ $message }}</div>
                @enderror
            @elseif ($environment->target_authorized_at)
                <p class="-mt-2 text-[11px] text-at-muted">
                    {{ __('Autorisation confirmée le :date.', ['date' => $environment->target_authorized_at->format('d/m/Y')]) }}
                </p>
            @endif

            <button
                type="submit"
                class="mt-1 w-full cursor-pointer rounded-md bg-linear-to-r from-at-violet-2 to-at-cyan-2 py-2.25 text-center text-[12px] font-semibold text-at-bg shadow-[0_0_14px_oklch(65%_0.16_280_/_0.4)]"
            >
                {{ __('Enregistrer') }}
            </button>
        </form>
    </div>
</div>
