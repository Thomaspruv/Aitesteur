<x-legal-layout :title="__('Politique de confidentialité')" updated="{{ now()->translatedFormat('d F Y') }}">
    <section>
        <p>
            Cette politique décrit comment <span class="at-legal-fill">[à compléter — raison sociale, voir mentions légales]</span>,
            responsable du traitement, collecte et utilise les données personnelles dans le cadre du service {{ config('app.name') }},
            conformément au Règlement général sur la protection des données (RGPD) et à la loi Informatique et Libertés.
        </p>
    </section>

    <section>
        <h2>{{ __('1. Données que nous collectons') }}</h2>
        <ul class="mt-3">
            <li><strong>{{ __('Compte') }} :</strong> {{ __('nom, adresse email, mot de passe (haché), et le cas échéant identifiant de passkey ou secret de double authentification.') }}</li>
            <li><strong>{{ __('Facturation') }} :</strong> {{ __("pour les abonnements Pro, les informations de paiement sont saisies et conservées directement par Stripe, notre prestataire de paiement — nous ne stockons jamais de numéro de carte bancaire sur nos serveurs.") }}</li>
            <li><strong>{{ __('Configuration projet') }} :</strong> {{ __("URL des environnements testés, scénarios de test rédigés par vous, et clé d'API du fournisseur IA que vous choisissez de renseigner (chiffrée en base)." ) }}</li>
            <li><strong>{{ __("Données d'exécution") }} :</strong> {{ __('captures d’écran, éléments d’interface détectés et journaux techniques générés lors des crawls et des runs, nécessaires au fonctionnement du Service.') }}</li>
            <li><strong>{{ __('Données techniques') }} :</strong> {{ __('adresse IP, journaux de connexion et cookies strictement nécessaires au fonctionnement (voir section 5).') }}</li>
        </ul>
    </section>

    <section>
        <h2>{{ __('2. À qui appartiennent les données visitées par le crawler') }}</h2>
        <p class="mt-3">
            Lorsque vous demandez une découverte ou l'exécution d'un scénario, notre crawler visite l'URL que vous avez déclarée et
            explicitement autorisée. Il peut de ce fait rencontrer des données affichées sur cette application (y compris, potentiellement,
            des données personnelles de vos propres utilisateurs si votre environnement en contient). Vous restez responsable, en tant que
            responsable de traitement pour votre propre application, de vous assurer que le test de cette URL par notre Service est
            conforme à vos propres obligations RGPD. Nous n'utilisons ces données qu'aux fins d'exécution du test demandé et ne les
            revendons ni ne les exploitons à d'autres fins.
        </p>
    </section>

    <section>
        <h2>{{ __('3. Finalités et bases légales') }}</h2>
        <ul class="mt-3">
            <li>{{ __("Fourniture du Service (exécution du contrat) : création de compte, exécution des crawls et runs, facturation.") }}</li>
            <li>{{ __('Sécurité (intérêt légitime) : détection de cibles réseau non autorisées, prévention des abus.') }}</li>
            <li>{{ __('Amélioration du produit (intérêt légitime) : suivi de la consommation IA pour maîtriser les coûts et diagnostiquer les incidents.') }}</li>
            <li>{{ __('Obligations légales : conservation des données de facturation.') }}</li>
        </ul>
    </section>

    <section>
        <h2>{{ __('4. Destinataires et sous-traitants') }}</h2>
        <p class="mt-3">{{ __('Certaines données sont partagées avec les prestataires suivants, dans la stricte mesure nécessaire :') }}</p>
        <ul class="mt-3">
            <li><strong>Stripe</strong> — {{ __('traitement des paiements et gestion des abonnements.') }}</li>
            <li><strong>{{ __('Fournisseur IA choisi par vous') }}</strong> — {{ __("les éléments de page et instructions de scénario nécessaires à l'exécution des runs lui sont transmis via la clé d'API que vous avez configurée (voir CGU/CGV §5)." ) }}</li>
            <li><strong>{{ __("Hébergeur") }}</strong> — <span class="at-legal-fill">[à compléter — nom de l'hébergeur, voir mentions légales]</span></li>
            <li><strong>{{ __("Prestataire d'envoi d'emails") }}</strong> — <span class="at-legal-fill">[à compléter une fois le fournisseur d'email transactionnel choisi]</span></li>
        </ul>
    </section>

    <section>
        <h2>{{ __('5. Cookies') }}</h2>
        <p class="mt-3">
            {{ config('app.name') }} n'utilise aucun cookie publicitaire ou de mesure d'audience tierce. Seuls des cookies strictement
            nécessaires au fonctionnement du Service sont déposés :
        </p>
        <ul class="mt-3">
            <li><strong>{{ __('Session') }} :</strong> {{ __('maintien de votre connexion et du contexte de sécurité (jeton CSRF).') }}</li>
            <li><strong>{{ __('Authentification persistante') }} :</strong> {{ __('cookie "se souvenir de moi", si vous l’activez.') }}</li>
            <li><strong>{{ __('Paiement') }} :</strong> {{ __("cookies déposés par Stripe pendant le parcours de paiement (checkout), nécessaires à la lutte antifraude.") }}</li>
        </ul>
        <p class="mt-3">{{ __("Ces cookies étant strictement nécessaires, ils ne requièrent pas de consentement préalable au titre de la réglementation applicable.") }}</p>
    </section>

    <section>
        <h2>{{ __('6. Durée de conservation') }}</h2>
        <p class="mt-3">
            {{ __("Les données de compte sont conservées tant que le compte est actif, puis supprimées ou anonymisées dans un délai raisonnable après suppression du compte. Les données de facturation sont conservées pour la durée imposée par les obligations comptables et fiscales.") }}
        </p>
    </section>

    <section>
        <h2>{{ __('7. Vos droits') }}</h2>
        <p class="mt-3">
            {{ __("Conformément au RGPD, vous disposez d'un droit d'accès, de rectification, d'effacement, de limitation, d'opposition et de portabilité sur vos données personnelles. Vous pouvez exercer ces droits en nous contactant à l'adresse suivante :") }}
            <span class="at-legal-fill">[à compléter — adresse email de contact RGPD]</span>.
            {{ __("Vous disposez également du droit d'introduire une réclamation auprès de la CNIL (www.cnil.fr).") }}
        </p>
    </section>

    <p class="text-[12.5px] text-at-muted">
        {{ __('Voir aussi :') }}
        <a href="{{ route('legal.mentions') }}">{{ __('Mentions légales') }}</a> — <a href="{{ route('legal.cgu') }}">{{ __('CGU/CGV') }}</a>
    </p>
</x-legal-layout>
