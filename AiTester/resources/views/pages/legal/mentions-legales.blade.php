<x-legal-layout :title="__('Mentions légales')" updated="{{ now()->translatedFormat('d F Y') }}">
    <section>
        <p class="mb-3 rounded-lg border border-verdict-broken/30 bg-verdict-broken/10 p-3 text-[12.5px] text-verdict-broken">
            {{ __('Page générée automatiquement : chaque encadré') }} <span class="at-legal-fill">[à compléter]</span>
            {{ __("doit être renseigné avec les informations réelles de l'éditeur avant mise en ligne publique. Ces mentions sont obligatoires en droit français (art. 6-III de la LCEN).") }}
        </p>
    </section>

    <section>
        <h2>{{ __('Éditeur du site') }}</h2>
        <p class="mt-3">
            Le site {{ config('app.name') }}, accessible à l'adresse {{ config('app.url') }}, est édité par :
        </p>
        <ul class="mt-3">
            <li><strong>{{ __('Raison sociale / nom') }} :</strong> <span class="at-legal-fill">[à compléter]</span></li>
            <li><strong>{{ __('Forme juridique') }} :</strong> <span class="at-legal-fill">[à compléter — ex. SASU, auto-entrepreneur]</span></li>
            <li><strong>{{ __('Capital social') }} :</strong> <span class="at-legal-fill">[à compléter, le cas échéant]</span></li>
            <li><strong>{{ __('Siège social') }} :</strong> <span class="at-legal-fill">[à compléter]</span></li>
            <li><strong>{{ __("Numéro d'immatriculation (SIREN/SIRET)") }} :</strong> <span class="at-legal-fill">[à compléter]</span></li>
            <li><strong>{{ __('Numéro de TVA intracommunautaire') }} :</strong> <span class="at-legal-fill">[à compléter, le cas échéant]</span></li>
            <li><strong>{{ __('Directeur de la publication') }} :</strong> <span class="at-legal-fill">[à compléter]</span></li>
            <li><strong>{{ __('Contact') }} :</strong> <span class="at-legal-fill">[à compléter — adresse email]</span></li>
        </ul>
    </section>

    <section>
        <h2>{{ __('Hébergement') }}</h2>
        <p class="mt-3">Le site et l'application sont hébergés par :</p>
        <ul class="mt-3">
            <li><strong>{{ __('Raison sociale') }} :</strong> <span class="at-legal-fill">[à compléter — ex. OVH SAS, AWS, Hetzner…]</span></li>
            <li><strong>{{ __('Adresse') }} :</strong> <span class="at-legal-fill">[à compléter]</span></li>
            <li><strong>{{ __('Téléphone') }} :</strong> <span class="at-legal-fill">[à compléter]</span></li>
        </ul>
    </section>

    <section>
        <h2>{{ __('Propriété intellectuelle') }}</h2>
        <p class="mt-3">
            L'ensemble des éléments du site {{ config('app.name') }} (textes, marques, logos, interface, code source) est protégé par le droit
            de la propriété intellectuelle et demeure la propriété exclusive de l'éditeur, sauf mention contraire. Toute reproduction ou
            représentation, totale ou partielle, sans autorisation préalable est interdite.
        </p>
    </section>

    <section>
        <h2>{{ __('Responsabilité') }}</h2>
        <p class="mt-3">
            {{ config('app.name') }} exécute, à la demande de l'utilisateur et sous son entière responsabilité, des visites automatisées
            (« crawl ») et des scénarios de test sur des URL que l'utilisateur déclare explicitement être autorisé à tester. L'éditeur ne
            saurait être tenu responsable d'une utilisation du service sur une cible que l'utilisateur n'était pas autorisé à tester, ni des
            conséquences d'actions automatisées (clics, remplissage de formulaires) exécutées conformément aux scénarios configurés par
            l'utilisateur lui-même.
        </p>
    </section>

    <section>
        <h2>{{ __('Droit applicable') }}</h2>
        <p class="mt-3">Les présentes mentions légales sont soumises au droit français. Tout litige relève de la compétence des tribunaux français.</p>
    </section>

    <p class="text-[12.5px] text-at-muted">
        {{ __('Voir aussi :') }}
        <a href="{{ route('legal.cgu') }}">{{ __('CGU/CGV') }}</a> — <a href="{{ route('legal.privacy') }}">{{ __('Politique de confidentialité') }}</a>
    </p>
</x-legal-layout>
