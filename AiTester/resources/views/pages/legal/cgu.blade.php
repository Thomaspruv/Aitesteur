<x-legal-layout :title="__('Conditions générales d’utilisation et de vente')" updated="{{ now()->translatedFormat('d F Y') }}">
    <section>
        <p>
            Les présentes conditions générales d'utilisation et de vente (« CGU/CGV ») régissent l'accès et l'utilisation du service
            {{ config('app.name') }} (le « Service »), édité par <span class="at-legal-fill">[à compléter — raison sociale, voir mentions légales]</span>
            (« l'Éditeur »). En créant un compte, l'utilisateur (« le Client ») accepte sans réserve les présentes CGU/CGV.
        </p>
    </section>

    <section>
        <h2>{{ __('1. Objet du service') }}</h2>
        <p class="mt-3">
            {{ config('app.name') }} est un outil de test qualité automatisé qui, à partir d'une URL fournie par le Client, explore
            l'application du Client (« découverte »), propose des parcours de test candidats, puis rejoue ces parcours dans un navigateur
            piloté par une intelligence artificielle afin de produire un verdict (réussite, réussite avec auto-réparation, changement
            détecté, ou rupture).
        </p>
    </section>

    <section>
        <h2>{{ __('2. Autorisation préalable et responsabilité du Client') }}</h2>
        <p class="mt-3">
            Le Client garantit être le propriétaire de la cible testée, ou disposer d'une autorisation explicite de son propriétaire, avant
            de déclarer une URL dans le Service. Le Client coche une case de confirmation d'autorisation avant tout crawl ou run — cette
            confirmation engage sa seule responsabilité. L'Éditeur met en œuvre des garde-fous techniques (blocage des cibles réseau
            privées/internes) mais ne vérifie pas et ne peut pas vérifier la réalité de l'autorisation déclarée par le Client.
        </p>
        <p class="mt-3">
            Le Client reste seul responsable du contenu de son application (y compris toute donnée personnelle de tiers qui y serait
            visible) et des conséquences des actions automatisées exécutées par le Service conformément aux scénarios qu'il configure
            (clics, remplissage de formulaires, navigation).
        </p>
    </section>

    <section>
        <h2>{{ __('3. Compte, organisations et projets') }}</h2>
        <p class="mt-3">
            L'utilisation du Service nécessite la création d'un compte. Chaque compte est rattaché à une organisation et à un ou plusieurs
            projets. Le Client est responsable de la confidentialité de ses identifiants et de toute activité réalisée depuis son compte.
        </p>
    </section>

    <section>
        <h2>{{ __('4. Offres et tarifs') }}</h2>
        <p class="mt-3">Le Service est proposé selon deux formules, décrites en détail sur la <a href="{{ route('home') }}#tarifs">page tarifs</a> :</p>
        <ul class="mt-3">
            <li><strong>Free</strong> — {{ __('gratuit, 1 environnement, runs illimités.') }}</li>
            <li><strong>Pro</strong> — {{ __('19 € HT / mois, sans limite d’environnement, facturation récurrente mensuelle.') }}</li>
        </ul>
        <p class="mt-3">
            L'abonnement Pro est géré par carte bancaire via notre prestataire de paiement Stripe, directement depuis l'application, après
            création du compte. Le Client peut résilier son abonnement à tout moment depuis son espace de gestion d'abonnement ; la
            résiliation prend effet à la fin de la période de facturation en cours, sans remboursement au prorata sauf disposition légale
            contraire.
        </p>
    </section>

    <section>
        <h2>{{ __('5. Utilisation d’un fournisseur IA tiers') }}</h2>
        <p class="mt-3">
            Le Client configure, par projet, une clé d'API auprès d'un fournisseur d'intelligence artificielle tiers de son choix. Cette clé
            et les données transmises pour l'exécution des tests (éléments d'interface visibles, instructions de scénario) sont envoyées
            directement à ce fournisseur pour le compte du Client, dans les conditions propres à ce fournisseur. L'Éditeur n'est pas partie
            à cette relation contractuelle et ne saurait être tenu responsable de la disponibilité, du coût ou du traitement des données par
            ce fournisseur tiers.
        </p>
    </section>

    <section>
        <h2>{{ __('6. Disponibilité et évolutions') }}</h2>
        <p class="mt-3">
            {{ config('app.name') }} est un produit en cours de développement actif. L'Éditeur s'efforce d'assurer la continuité du Service
            mais ne garantit pas une disponibilité ininterrompue et peut faire évoluer, suspendre ou modifier des fonctionnalités,
            notamment pendant les phases de développement.
        </p>
    </section>

    <section>
        <h2>{{ __('7. Résiliation') }}</h2>
        <p class="mt-3">
            Le Client peut supprimer son compte à tout moment. L'Éditeur peut suspendre ou résilier l'accès d'un compte en cas d'usage du
            Service contraire à la loi, aux présentes CGU/CGV, ou en cas de cible testée sans autorisation valable.
        </p>
    </section>

    <section>
        <h2>{{ __('8. Droit applicable et litiges') }}</h2>
        <p class="mt-3">
            Les présentes CGU/CGV sont soumises au droit français. À défaut de résolution amiable, tout litige relève de la compétence des
            tribunaux français.
            <span class="at-legal-fill">[à compléter si applicable — nom et coordonnées du médiateur de la consommation]</span>
        </p>
    </section>

    <p class="text-[12.5px] text-at-muted">
        {{ __('Voir aussi :') }}
        <a href="{{ route('legal.mentions') }}">{{ __('Mentions légales') }}</a> — <a href="{{ route('legal.privacy') }}">{{ __('Politique de confidentialité') }}</a>
    </p>
</x-legal-layout>
