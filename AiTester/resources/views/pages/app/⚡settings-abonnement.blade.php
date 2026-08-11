<?php

use App\Models\Organization;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Abonnement')] #[Layout('layouts::product', ['activeNav' => 'settings-abonnement'])] class extends Component {
    public Project $project;

    public Organization $organization;

    public function mount(): void
    {
        $this->project = Auth::user()->currentProject();

        $this->authorize('view', $this->project);

        $this->organization = $this->project->organization;
    }

    /**
     * Redirects with a plain URL string via $this->redirect() rather than
     * returning a RedirectResponse — Livewire rebinds the 'redirect'
     * container service for the duration of an action (see
     * Livewire\Features\SupportRedirects), so a real HTTP redirect built
     * inside the action (whether from the redirect() helper or, more
     * subtly, from Cashier's own Checkout::redirect()/redirectToBillingPortal()
     * internals) never reaches the browser — only $this->redirect() is
     * wired into Livewire's own effect system.
     */
    public function subscribeToPro(): void
    {
        $this->authorize('view', $this->project);

        $priceId = config('services.stripe.price_pro');

        if (! $priceId) {
            Flux::toast(variant: 'warning', text: __("Le plan Pro n'est pas encore configuré côté paiement."));

            return;
        }

        $checkout = $this->organization
            ->newSubscription('default', $priceId)
            ->checkout([
                'success_url' => route('settings-abonnement').'?checkout=success',
                'cancel_url' => route('settings-abonnement').'?checkout=cancel',
            ]);

        $this->redirect($checkout->url);
    }

    public function manageBilling(): void
    {
        $this->authorize('view', $this->project);

        $this->redirect($this->organization->billingPortalUrl(route('settings-abonnement')));
    }

}; ?>

<div class="max-w-3xl">
    <div class="mb-5">
        <div class="text-[15px] font-semibold">{{ __('Abonnement') }}</div>
        <p class="text-[12.5px] text-at-muted">
            {{ __("Gère le plan de facturation de l'organisation :organization.", ['organization' => $organization->name]) }}
        </p>
    </div>

    @if ($organization->subscribed('default'))
        <div class="mb-5 flex items-center gap-2.5 rounded-[11px] border border-at-violet/30 bg-linear-to-br from-at-violet/15 to-at-bg/40 p-4">
            <span class="size-[7px] shrink-0 animate-[at-pulse-dot_2.2s_ease-in-out_infinite] rounded-full bg-verdict-pass" style="box-shadow: 0 0 6px var(--color-verdict-pass)"></span>
            <div>
                <div class="text-[13px] font-semibold">{{ __('Plan Pro actif') }}</div>
                <div class="text-[11.5px] text-at-muted">{{ __('19 €/mois') }}</div>
            </div>
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-[14px] border border-at-border bg-at-surface p-6 backdrop-blur-md">
            <div class="mb-1 text-[15px] font-semibold">{{ __('Plan gratuit') }}</div>
            <p class="mb-5 text-[12.5px] text-at-muted">{{ __('Pour découvrir airtight sur un premier projet.') }}</p>
            <div class="mb-6 flex items-baseline gap-1">
                <span class="font-data text-[28px] font-bold">0 €</span>
                <span class="text-[12.5px] text-at-muted">{{ __('/mois') }}</span>
            </div>
            <ul class="mb-6 flex flex-col gap-2.5 text-[12.5px] text-at-muted-2">
                <li class="flex items-center gap-2"><span class="size-1.5 shrink-0 rounded-full bg-verdict-pass"></span> {{ __('1 environnement') }}</li>
                <li class="flex items-center gap-2"><span class="size-1.5 shrink-0 rounded-full bg-verdict-pass"></span> {{ __('Découverte automatique') }}</li>
                <li class="flex items-center gap-2"><span class="size-1.5 shrink-0 rounded-full bg-verdict-pass"></span> {{ __('Runs illimités') }}</li>
            </ul>
            @unless ($organization->subscribed('default'))
                <div class="rounded-md border border-at-border-2 py-2.25 text-center text-[12px] font-semibold text-at-muted">{{ __('Plan actuel') }}</div>
            @endunless
        </div>

        <div class="relative rounded-[14px] border border-at-violet/40 bg-at-violet/10 p-6 backdrop-blur-md">
            <div class="mb-1 text-[15px] font-semibold">{{ __('Pro') }}</div>
            <p class="mb-5 text-[12.5px] text-at-muted">{{ __('Pour aller plus loin sur vos environnements de test.') }}</p>
            <div class="mb-6 flex items-baseline gap-1">
                <span class="font-data text-[28px] font-bold">19 €</span>
                <span class="text-[12.5px] text-at-muted">{{ __('/mois') }}</span>
            </div>
            <ul class="mb-6 flex flex-col gap-2.5 text-[12.5px] text-at-text-2">
                <li class="flex items-center gap-2"><span class="size-1.5 shrink-0 rounded-full bg-at-violet"></span> {{ __('Tout le plan gratuit, sans limite') }}</li>
                <li class="flex items-center gap-2"><span class="size-1.5 shrink-0 rounded-full bg-at-violet"></span> {{ __('Environnements supplémentaires') }}</li>
                <li class="flex items-center gap-2"><span class="size-1.5 shrink-0 rounded-full bg-at-violet"></span> {{ __('Support prioritaire') }}</li>
            </ul>

            @if ($organization->subscribed('default'))
                <button
                    wire:click="manageBilling"
                    type="button"
                    class="w-full cursor-pointer rounded-md border border-at-border-2 py-2.25 text-center text-[12px] font-semibold text-at-text hover:bg-at-surface-3"
                >
                    {{ __('Gérer mon abonnement') }}
                </button>
            @else
                <button
                    wire:click="subscribeToPro"
                    type="button"
                    class="w-full cursor-pointer rounded-md bg-linear-to-r from-at-violet-2 to-at-cyan-2 py-2.25 text-center text-[12px] font-semibold text-at-bg shadow-[0_0_14px_oklch(65%_0.16_280_/_0.4)]"
                >
                    {{ __('Passer à Pro — 19 €/mois') }}
                </button>
            @endif
        </div>
    </div>
</div>
