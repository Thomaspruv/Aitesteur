<?php

use App\Enums\AiProvider;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Réglages IA')] #[Layout('layouts::product', ['activeNav' => 'settings-ai'])] class extends Component {
    public Project $project;

    public ?string $aiProvider = null;

    public ?string $aiModel = null;

    public string $aiApiKey = '';

    public function mount(): void
    {
        $this->project = Auth::user()->currentProject();

        $this->authorize('view', $this->project);

        $this->aiProvider = $this->project->ai_provider?->value;
        $this->aiModel = $this->project->ai_model;
    }

    public function updatedAiProvider(): void
    {
        $this->aiModel = null;
    }

    /**
     * @return array<string, string>
     */
    public function models(): array
    {
        return $this->aiProvider ? AiProvider::from($this->aiProvider)->models() : [];
    }

    public function save(): void
    {
        $this->authorize('view', $this->project);

        $this->validate([
            'aiProvider' => ['nullable', Rule::enum(AiProvider::class)],
            'aiModel' => ['nullable', 'string', Rule::in(array_keys($this->models()))],
            'aiApiKey' => ['nullable', 'string', 'max:500'],
        ]);

        $data = [
            'ai_provider' => $this->aiProvider,
            'ai_model' => $this->aiModel,
        ];

        if ($this->aiApiKey !== '') {
            $data['ai_api_key'] = $this->aiApiKey;
        }

        $this->project->update($data);

        $this->aiApiKey = '';

        Flux::toast(variant: 'success', text: __('Réglages IA enregistrés.'));
    }
}; ?>

<div class="max-w-xl">
    <div class="rounded-[14px] border border-at-border bg-at-surface p-6 backdrop-blur-md">
        <div class="mb-1 text-[15px] font-semibold">{{ __('Fournisseur IA') }}</div>
        <p class="mb-5 text-[12.5px] text-at-muted">
            {{ __("Configure le fournisseur qui compile tes descriptions en langage naturel (écran Découverte) en parcours structurés. Sans configuration, la description est enregistrée telle quelle.") }}
        </p>

        <form wire:submit="save" class="flex flex-col gap-4">
            <div>
                <label class="mb-1.5 block text-[12px] font-medium text-at-muted-2">{{ __('Fournisseur') }}</label>
                <select
                    wire:model.live="aiProvider"
                    class="w-full rounded-[9px] border border-at-border-2 bg-at-bg px-3 py-2.5 text-[12.5px] text-at-text-2 focus:border-at-violet focus:outline-none"
                >
                    <option value="">{{ __('Aucun') }}</option>
                    @foreach (\App\Enums\AiProvider::cases() as $provider)
                        <option value="{{ $provider->value }}">{{ $provider->label() }}</option>
                    @endforeach
                </select>
                @error('aiProvider')
                    <div class="mt-1.5 text-[11.5px] text-verdict-broken">{{ $message }}</div>
                @enderror
            </div>

            @if ($aiProvider)
                <div>
                    <label class="mb-1.5 block text-[12px] font-medium text-at-muted-2">{{ __('Modèle') }}</label>
                    <select
                        wire:model="aiModel"
                        class="w-full rounded-[9px] border border-at-border-2 bg-at-bg px-3 py-2.5 text-[12.5px] text-at-text-2 focus:border-at-violet focus:outline-none"
                    >
                        <option value="">{{ __('Choisir un modèle') }}</option>
                        @foreach ($this->models() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1.5 block text-[12px] font-medium text-at-muted-2">{{ __('Clé API') }}</label>
                    <input
                        type="password"
                        wire:model="aiApiKey"
                        placeholder="{{ $project->ai_api_key ? __('Clé enregistrée — laisse vide pour la conserver') : 'sk-...' }}"
                        class="w-full rounded-[9px] border border-at-border-2 bg-at-bg px-3 py-2.5 text-[12.5px] text-at-text-2 placeholder:text-at-muted focus:border-at-violet focus:outline-none"
                    />
                    @error('aiApiKey')
                        <div class="mt-1.5 text-[11.5px] text-verdict-broken">{{ $message }}</div>
                    @enderror
                </div>
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
