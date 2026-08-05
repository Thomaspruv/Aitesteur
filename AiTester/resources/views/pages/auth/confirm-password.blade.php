<x-layouts::auth :title="__('Confirmer le mot de passe')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Confirmer le mot de passe')"
            :description="__('Ceci est une zone sécurisée de l\'application. Confirmez votre mot de passe avant de continuer.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify
            options-route="passkey.confirm-options"
            submit-route="passkey.confirm"
            :label="__('Confirmer avec une clé d\'accès')"
            :loading-label="__('Confirmation…')"
            :separator="__('Ou confirmer avec le mot de passe')"
        />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="password"
                :label="__('Mot de passe')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Mot de passe')"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="confirm-password-button">
                {{ __('Confirmer') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
